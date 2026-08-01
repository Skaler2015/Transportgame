<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesCompany;
use App\Http\Controllers\Controller;
use App\Http\Resources\ContractResource;
use App\Http\Resources\ShipmentResource;
use App\Models\Contract;
use App\Models\Driver;
use App\Models\Trailer;
use App\Models\Vehicle;
use App\Models\WorldEvent;
use App\Services\CompanyService;
use App\Services\EconomyService;
use App\Services\ShipmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ContractController extends Controller
{
    use ResolvesCompany;

    public function __construct(
        private readonly CompanyService $companies,
        private readonly ShipmentService $shipments,
    ) {}

    /** The open contract market, filterable and sortable. */
    public function index(Request $request)
    {
        $filters = $request->validate([
            'origin_city_id' => ['nullable', 'integer', 'exists:cities,id'],
            'destination_city_id' => ['nullable', 'integer', 'exists:cities,id'],
            'commodity_id' => ['nullable', 'integer', 'exists:commodities,id'],
            'sort' => ['nullable', 'in:payout,distance_km,difficulty,deadline_at'],
            'haulable' => ['nullable', 'boolean'],
        ]);

        $company = $this->company($request);

        // Keep the market alive even if the world-tick cron isn't running: top
        // up open contracts when a player looks at the board (rate-limited).
        $this->ensureMarketFresh();

        $query = Contract::onMarket()->with(['commodity', 'origin', 'destination'])
            // Only domestic contracts — origin city in the player's country.
            ->whereHas('origin', fn ($q) => $q->where('country', $company->country));

        foreach (['origin_city_id', 'destination_city_id', 'commodity_id'] as $f) {
            if (! empty($filters[$f])) {
                $query->where($f, $filters[$f]);
            }
        }

        $sort = $filters['sort'] ?? 'payout';
        $query->orderByDesc($sort === 'payout' ? 'payout' : $sort);
        if ($sort !== 'payout') {
            $query->reorder()->orderBy($sort);
        }

        // When requested, keep only contracts at least one AVAILABLE, compatible
        // vehicle in the player's fleet could actually haul.
        if (filter_var($filters['haulable'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $company = $this->company($request);
            $fleet = Vehicle::where('company_id', $company->id)
                ->where('status', Vehicle::STATUS_IDLE)
                ->where('condition', '>', 15)
                ->with('model')->get();

            $contracts = $query->limit(300)->get()
                ->filter(fn (Contract $c) => $this->haulableBy($c, $fleet))
                ->take(60)->values();

            return ContractResource::collection($contracts);
        }

        return ContractResource::collection($query->limit(60)->get());
    }

    /**
     * Self-healing market: at most once per cooldown, expire stale offers and
     * mint fresh ones so the board never sits empty when the scheduled
     * world-tick isn't firing. Cheap no-op while on cooldown.
     */
    private function ensureMarketFresh(): void
    {
        if (! Cache::add('market:replenish-lock', 1, now()->addSeconds(45))) {
            return; // another request refreshed it very recently
        }

        try {
            $economy = app(EconomyService::class);
            $events = WorldEvent::active()->get();
            $economy->expireStaleContracts();
            $economy->replenishContracts($events);
        } catch (\Throwable $e) {
            Log::error('Market replenish failed: '.$e->getMessage());
        }
    }

    /** True if any vehicle in $fleet can carry this contract's cargo. */
    private function haulableBy(Contract $contract, Collection $fleet): bool
    {
        $commodity = $contract->commodity;
        $weight = $commodity->weight_per_unit * $contract->units;
        $volume = $commodity->volume_per_unit * $contract->units;

        return $fleet->contains(function (Vehicle $v) use ($commodity, $weight, $volume) {
            return $commodity->canBeCarriedBy($v->model)
                && $weight <= $v->effectiveCapacityWeight() + 0.001
                && $volume <= $v->effectiveCapacityVolume() + 0.001;
        });
    }

    /** Contracts this company has accepted or is running. */
    public function mine(Request $request)
    {
        $company = $this->company($request);

        $contracts = Contract::where('company_id', $company->id)
            ->whereIn('status', [Contract::STATUS_ACCEPTED, Contract::STATUS_IN_PROGRESS])
            ->with(['commodity', 'origin', 'destination'])
            ->orderByDesc('created_at')
            ->get();

        return ContractResource::collection($contracts);
    }

    /** Claim an open contract. */
    public function accept(Request $request, Contract $contract)
    {
        $company = $this->company($request);

        try {
            $accepted = $this->companies->acceptContract($company, $contract);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return new ContractResource($accepted);
    }

    /** Claim an open contract AND dispatch a truck against it in one action. */
    public function dispatch(Request $request, Contract $contract)
    {
        $company = $this->company($request);

        $data = $request->validate([
            'vehicle_id' => ['required', 'integer'],
            'driver_id' => ['required', 'integer'],
            'trailer_id' => ['nullable', 'integer'],
        ]);

        $vehicle = Vehicle::where('id', $data['vehicle_id'])->where('company_id', $company->id)->firstOrFail();
        $driver = Driver::where('id', $data['driver_id'])->where('company_id', $company->id)->firstOrFail();
        $trailer = ! empty($data['trailer_id'])
            ? Trailer::where('id', $data['trailer_id'])->where('company_id', $company->id)->firstOrFail()
            : null;

        try {
            $shipment = DB::transaction(function () use ($company, $contract, $vehicle, $driver, $trailer) {
                if ($contract->status === Contract::STATUS_OPEN) {
                    $this->companies->acceptContract($company, $contract);
                    $contract->refresh();
                }
                if ($contract->company_id !== $company->id || $contract->status !== Contract::STATUS_ACCEPTED) {
                    throw new RuntimeException('This contract is no longer available.');
                }

                return $this->shipments->dispatch($company, $contract, $vehicle, $driver, $trailer);
            });
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return (new ShipmentResource(
            $shipment->load(['contract.commodity', 'contract.origin', 'contract.destination', 'vehicle.model', 'trailer.model', 'driver'])
        ))->response()->setStatusCode(201);
    }
}
