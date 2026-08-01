<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesCompany;
use App\Http\Controllers\Controller;
use App\Http\Resources\CompanyResource;
use App\Http\Resources\LedgerEntryResource;
use App\Http\Resources\ShipmentResource;
use App\Http\Resources\WorldEventResource;
use App\Models\Contract;
use App\Models\LedgerEntry;
use App\Models\Shipment;
use App\Models\Vehicle;
use App\Models\WorldEvent;
use App\Services\ShipmentService;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    use ResolvesCompany;

    public function __construct(private readonly ShipmentService $shipments) {}

    public function show(Request $request)
    {
        $company = $this->company($request)->loadCount('vehicles', 'drivers')->load('headquarters');

        return new CompanyResource($company);
    }

    /** One aggregated payload powering the main dashboard. */
    public function dashboard(Request $request)
    {
        $company = $this->company($request);

        // Settle any deliveries that have reached their ETA before we read the
        // dashboard, so completed runs bank their payout on the next page load
        // rather than waiting on the world cron.
        $this->shipments->resolveDueFor($company);

        $activeShipments = Shipment::where('company_id', $company->id)
            ->where('status', Shipment::STATUS_EN_ROUTE)
            ->with(['contract.commodity', 'contract.origin', 'contract.destination', 'vehicle.model', 'trailer.model', 'driver'])
            ->orderBy('eta_at')
            ->get();

        $fleet = Vehicle::where('company_id', $company->id)
            ->selectRaw('status, count(*) as n')
            ->groupBy('status')->pluck('n', 'status');

        // P&L: sum ledger by category (all-time for this slice).
        $pnl = LedgerEntry::where('company_id', $company->id)
            ->selectRaw('category, sum(amount) as total')
            ->groupBy('category')
            ->pluck('total', 'category');

        $recentLedger = LedgerEntry::where('company_id', $company->id)
            ->orderByDesc('occurred_at')->limit(12)->get();

        return response()->json([
            'company' => new CompanyResource($company->loadCount('vehicles', 'drivers')->load('headquarters')),
            'active_shipments' => ShipmentResource::collection($activeShipments),
            'fleet_summary' => [
                'idle' => (int) ($fleet['idle'] ?? 0),
                'en_route' => (int) ($fleet['en_route'] ?? 0),
                'maintenance' => (int) ($fleet['maintenance'] ?? 0),
                'total' => (int) $fleet->sum(),
            ],
            'pnl' => [
                'revenue' => (int) ($pnl['revenue'] ?? 0),
                'fuel' => (int) ($pnl['fuel'] ?? 0),
                'wages' => (int) ($pnl['wages'] ?? 0),
                'purchase' => (int) ($pnl['purchase'] ?? 0),
                'upkeep' => (int) ($pnl['upkeep'] ?? 0),
                'penalty' => (int) ($pnl['penalty'] ?? 0),
            ],
            'recent_ledger' => LedgerEntryResource::collection($recentLedger),
            'open_contracts' => Contract::onMarket()->count(),
            'my_open_contracts' => Contract::where('company_id', $company->id)
                ->whereIn('status', [Contract::STATUS_ACCEPTED, Contract::STATUS_IN_PROGRESS])->count(),
            'world_news' => WorldEventResource::collection(
                WorldEvent::active()->orderByDesc('starts_at')->limit(6)->get()
            ),
        ]);
    }

    /** Change the company's country (relocates HQ + fleet). */
    public function updateCountry(Request $request)
    {
        $company = $this->company($request);
        $data = $request->validate([
            'country' => ['required', 'string', 'in:'.implode(',', array_keys(config('transoria.countries')))],
        ]);

        if ($data['country'] === $company->country) {
            return response()->json(['message' => 'You are already based there.'], 422);
        }

        try {
            app(\App\Services\CompanyService::class)->relocateToCountry($company, $data['country']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Relocated to '.config('transoria.countries.'.$data['country']).'. Your fleet moved to the new HQ.',
        ]);
    }

    /** Advance or finish the welcome tutorial. */
    public function tutorial(Request $request)
    {
        $company = $this->company($request);

        $data = $request->validate([
            'step' => ['nullable', 'integer', 'min:0', 'max:50'],
            'done' => ['nullable', 'boolean'],
        ]);

        if (array_key_exists('step', $data) && $data['step'] !== null) {
            $company->tutorial_step = $data['step'];
        }
        if (! empty($data['done'])) {
            $company->onboarded_at = now();
        }
        $company->save();

        return new CompanyResource($company->loadCount('vehicles', 'drivers')->load('headquarters'));
    }

    /** Wipe the company's progress and start fresh (keeps the login). */
    public function reset(Request $request)
    {
        $company = $this->company($request);

        $fresh = app(\App\Services\CompanyService::class)->resetCompany($company);

        return response()->json([
            'message' => 'Fresh start! Your company has been reset to a single truck.',
            'company' => new CompanyResource($fresh->loadCount('vehicles', 'drivers')->load('headquarters')),
        ]);
    }

    /** Company financial ledger (paginated). */
    public function ledger(Request $request)
    {
        $company = $this->company($request);

        $entries = LedgerEntry::where('company_id', $company->id)
            ->orderByDesc('occurred_at')
            ->paginate(30);

        return LedgerEntryResource::collection($entries);
    }
}
