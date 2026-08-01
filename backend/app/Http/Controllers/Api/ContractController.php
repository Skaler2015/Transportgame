<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesCompany;
use App\Http\Controllers\Controller;
use App\Http\Resources\ContractResource;
use App\Http\Resources\ShipmentResource;
use App\Models\Contract;
use App\Models\Driver;
use App\Models\Shipment;
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
            'backhaul' => ['nullable', 'boolean'],
        ]);

        $company = $this->company($request);

        // Keep the market alive even if the world-tick cron isn't running: top
        // up open contracts when a player looks at the board (rate-limited).
        $this->ensureMarketFresh();

        // Keep every city connected with work for every vehicle class, and make
        // sure a free truck always has haulable jobs from where it's parked.
        $this->ensureCityCoverage($company->country);
        $this->ensureLocalWork($company);

        // The fleet used for the "haulable" filter and for BACKHAUL jobs — work
        // starting where a truck already is, so it doesn't run back empty. We
        // count BOTH idle trucks (available now) AND trucks currently EN ROUTE,
        // using their delivery destination — so as a truck drives to Indore, the
        // board already surfaces the next Indore-origin load it can pick up.
        $idleFleet = Vehicle::where('company_id', $company->id)
            ->where('status', Vehicle::STATUS_IDLE)
            ->where('condition', '>', 15)
            ->with('model')->get();

        $inbound = Shipment::where('company_id', $company->id)
            ->where('status', Shipment::STATUS_EN_ROUTE)
            ->with(['vehicle.model', 'contract:id,destination_city_id'])
            ->get();

        // Cities where a truck is, or will soon be.
        $idleCityIds = $idleFleet->pluck('city_id')->filter()->unique()->flip();
        $arrivingCityIds = $inbound->pluck('contract.destination_city_id')->filter()->unique()->flip();
        $fleetCityIds = $idleCityIds->keys()->merge($arrivingCityIds->keys())->unique()->flip();

        $query = Contract::onMarket()->with(['commodity', 'origin', 'destination'])
            // Domestic jobs (origin in the player's country) PLUS any city where a
            // truck of theirs is parked or heading — so a truck that ended up in
            // another region still sees local work and can get home.
            ->where(function ($q) use ($company, $fleetCityIds) {
                $q->whereHas('origin', fn ($o) => $o->where('country', $company->country));
                if ($fleetCityIds->isNotEmpty()) {
                    $q->orWhereIn('origin_city_id', $fleetCityIds->keys()->all());
                }
            });

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

        $wantHaulable = filter_var($filters['haulable'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $wantBackhaul = filter_var($filters['backhaul'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $contracts = $query->limit(300)->get();

        // "Only what my fleet can haul" means what an IDLE truck can take RIGHT
        // NOW — so a small free truck sees the light loads it can actually
        // dispatch, not heavy jobs only a bigger truck (still en route) could do.
        if ($wantHaulable) {
            // Trailers a tractor could actually attach right now (idle & healthy).
            $availableTrailers = Trailer::where('company_id', $company->id)
                ->where('status', Trailer::STATUS_IDLE)
                ->where('condition', '>', 10)
                ->with('model')->get();

            $contracts = $contracts->filter(fn (Contract $c) => $this->haulableBy($c, $idleFleet, $availableTrailers));
        }

        // Flag jobs starting where a truck is (idle) or is heading (arriving).
        $contracts->each(function (Contract $c) use ($fleetCityIds, $idleCityIds) {
            $c->at_fleet_city = $fleetCityIds->has($c->origin_city_id);
            $c->fleet_arriving = ! $idleCityIds->has($c->origin_city_id) && $fleetCityIds->has($c->origin_city_id);
        });

        if ($wantBackhaul) {
            $contracts = $contracts->filter(fn (Contract $c) => $c->at_fleet_city);
        }

        // Float jobs to the top by proximity of a truck: "Truck here" (idle at
        // origin) first, then "Truck arriving" (en route), then everything else —
        // keeping the chosen sort order within each group (stable in PHP 8+).
        $contracts = $contracts->sortByDesc(
            fn (Contract $c) => $c->at_fleet_city ? ($c->fleet_arriving ? 1 : 2) : 0
        );

        return ContractResource::collection($contracts->take(60)->values());
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

    /**
     * Ensure every city in the country has open work across all load sizes, so
     * any vehicle anywhere always has a contract. Rate-limited per country.
     */
    private function ensureCityCoverage(string $country): void
    {
        if (! Cache::add("city-coverage:{$country}", 1, now()->addSeconds(90))) {
            return;
        }

        try {
            app(EconomyService::class)->ensureCityCoverage($country);
        } catch (\Throwable $e) {
            Log::error('City coverage top-up failed: '.$e->getMessage());
        }
    }

    /**
     * Ensure each city where the company has a free truck offers a few jobs that
     * truck can haul. Cheap no-op while on a short per-company cooldown.
     */
    private function ensureLocalWork($company): void
    {
        if (! Cache::add("local-work:{$company->id}", 1, now()->addSeconds(20))) {
            return;
        }

        try {
            app(EconomyService::class)->ensureWorkForFleet($company);
        } catch (\Throwable $e) {
            Log::error('Fleet-work top-up failed: '.$e->getMessage());
        }
    }

    /**
     * True if a truck the player has PARKED AT THE ORIGIN can carry this cargo —
     * i.e. the job is dispatchable right now. Matching the dispatch picker (which
     * only offers trucks at the origin) means the board never shows a job that
     * would then say "no compatible idle vehicle".
     */
    private function haulableBy(Contract $contract, Collection $fleet, Collection $trailers = null): bool
    {
        $trailers ??= collect();
        $commodity = $contract->commodity;
        $weight = $commodity->weight_per_unit * $contract->units;
        $volume = $commodity->volume_per_unit * $contract->units;

        return $fleet->contains(function (Vehicle $v) use ($contract, $commodity, $weight, $volume, $trailers) {
            if ($v->city_id !== $contract->origin_city_id || ! $commodity->canBeCarriedBy($v->model)) {
                return false;
            }

            // A tractor that needs a trailer can only haul this if the company
            // actually has a matching, available trailer whose capacity fits —
            // otherwise the job can't be dispatched and shouldn't be listed.
            if ($v->model->needs_trailer) {
                return $trailers->contains(fn (Trailer $t) => $t->model
                    && $t->model->canCarry($commodity)
                    && $weight <= $t->model->capacity_weight + 0.001
                    && $volume <= $t->model->capacity_volume + 0.001);
            }

            return $weight <= $v->effectiveCapacityWeight() + 0.001
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
