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
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    use ResolvesCompany;

    public function show(Request $request)
    {
        $company = $this->company($request)->loadCount('vehicles', 'drivers')->load('headquarters');

        return new CompanyResource($company);
    }

    /** One aggregated payload powering the main dashboard. */
    public function dashboard(Request $request)
    {
        $company = $this->company($request);

        $activeShipments = Shipment::where('company_id', $company->id)
            ->where('status', Shipment::STATUS_EN_ROUTE)
            ->with(['contract.commodity', 'contract.origin', 'contract.destination', 'vehicle.model', 'driver'])
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
