<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesCompany;
use App\Http\Controllers\Controller;
use App\Http\Resources\LedgerEntryResource;
use App\Models\LedgerEntry;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Automated company accounting built from the always-on ledger: a period
 * Profit & Loss statement, a Balance Sheet (assets / liabilities / equity)
 * and the full, filterable transaction journal. Nothing is entered by hand —
 * every figure derives from journalled cash movements + live holdings.
 */
class AccountsController extends Controller
{
    use ResolvesCompany;

    /** Ledger categories grouped for the P&L statement. */
    private const OPERATING_EXPENSES = ['fuel', 'wages', 'upkeep', 'penalty'];
    private const CAPITAL_EXPENSES = ['purchase', 'research'];
    private const FINANCING = ['loan', 'interest'];

    public function summary(Request $request)
    {
        $company = $this->company($request);

        $period = in_array($request->query('period'), ['7d', '30d', 'all'], true)
            ? $request->query('period') : 'all';
        $since = match ($period) {
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            default => null,
        };

        // --- P&L: net by category over the period -------------------------
        $q = LedgerEntry::where('company_id', $company->id);
        if ($since) {
            $q->where('occurred_at', '>=', $since);
        }
        $byCat = (clone $q)->selectRaw('category, sum(amount) as total, count(*) as n')
            ->groupBy('category')->get()->keyBy('category');

        $cat = fn (string $k): int => (int) ($byCat[$k]->total ?? 0);

        $revenue = $cat('revenue');
        $operating = collect(self::OPERATING_EXPENSES)->sum($cat);  // negative
        $capital = collect(self::CAPITAL_EXPENSES)->sum($cat);      // negative
        $interest = $cat('interest');                               // negative

        $operatingProfit = $revenue + $operating;                  // opex are negative
        $netProfit = $revenue + $operating + $capital + $interest;

        // --- Balance sheet ------------------------------------------------
        $cash = (int) $company->cash;

        $fleetValue = (int) Vehicle::where('company_id', $company->id)
            ->join('vehicle_models', 'vehicles.vehicle_model_id', '=', 'vehicle_models.id')
            ->sum('vehicle_models.price');

        $inventoryValue = (int) round((float) (DB::table('warehouse_inventory')
            ->join('warehouses', 'warehouse_inventory.warehouse_id', '=', 'warehouses.id')
            ->where('warehouses.company_id', $company->id)
            ->selectRaw('COALESCE(SUM(avg_unit_cost * units), 0) as v')->value('v') ?? 0) * 100);

        $totalAssets = $cash + $fleetValue + $inventoryValue;
        $liabilities = (int) $company->debt;
        $equity = $totalAssets - $liabilities;

        return response()->json([
            'period' => $period,
            'pnl' => [
                'revenue' => $revenue,
                'operating_expenses' => [
                    'fuel' => $cat('fuel'),
                    'wages' => $cat('wages'),
                    'upkeep' => $cat('upkeep'),
                    'penalty' => $cat('penalty'),
                    'total' => $operating,
                ],
                'capital_expenses' => [
                    'purchase' => $cat('purchase'),
                    'research' => $cat('research'),
                    'total' => $capital,
                ],
                'interest' => $interest,
                'operating_profit' => $operatingProfit,
                'net_profit' => $netProfit,
            ],
            'financing' => [
                'loan_movement' => $cat('loan'),
                'interest' => $interest,
            ],
            'balance_sheet' => [
                'assets' => [
                    'cash' => $cash,
                    'fleet_value' => $fleetValue,
                    'inventory_value' => $inventoryValue,
                    'total' => $totalAssets,
                ],
                'liabilities' => [
                    'loans' => $liabilities,
                    'total' => $liabilities,
                ],
                'equity' => $equity,
            ],
            'lifetime' => [
                'revenue' => (int) $company->lifetime_revenue,
                'expenses' => (int) $company->lifetime_expenses,
                'net' => (int) ($company->lifetime_revenue - $company->lifetime_expenses),
                'shipments_completed' => (int) $company->shipments_completed,
                'shipments_failed' => (int) $company->shipments_failed,
            ],
        ]);
    }

    /**
     * Deep analytics for the dashboard: daily trends, expense breakdown, business
     * KPIs, and revenue split by commodity, route, vehicle and driver — all
     * derived from the ledger and live fleet, no manual entry.
     */
    public function analytics(Request $request)
    {
        $company = $this->company($request);
        $period = in_array($request->query('period'), ['7d', '30d', 'all'], true)
            ? $request->query('period') : '30d';
        $since = match ($period) {
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            default => now()->subDays(90), // cap "all" trend at 90 days for readability
        };

        $ship = 'App\\Models\\Shipment';

        // --- Daily trend: revenue in, expenses out, net --------------------
        $daily = LedgerEntry::where('company_id', $company->id)
            ->where('occurred_at', '>=', $since)
            ->selectRaw('DATE(occurred_at) as d')
            ->selectRaw('SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as revenue')
            ->selectRaw('SUM(CASE WHEN amount < 0 THEN -amount ELSE 0 END) as expense')
            ->groupBy('d')->orderBy('d')->get();

        $trend = $daily->map(fn ($r) => [
            'date' => $r->d,
            'revenue' => (int) $r->revenue,
            'expense' => (int) $r->expense,
            'net' => (int) ($r->revenue - $r->expense),
        ])->values();

        // --- Expense breakdown (positive magnitudes) -----------------------
        $expenseCats = ['fuel', 'wages', 'upkeep', 'penalty', 'purchase', 'interest'];
        $breakdown = [];
        foreach ($expenseCats as $c) {
            $sum = (int) LedgerEntry::where('company_id', $company->id)
                ->where('category', $c)->where('occurred_at', '>=', $since)
                ->sum('amount');
            if ($sum < 0) {
                $breakdown[$c] = -$sum;
            }
        }

        // --- Revenue splits via ledger→shipment→contract joins -------------
        $revBase = fn () => LedgerEntry::query()
            ->where('ledger_entries.company_id', $company->id)
            ->where('ledger_entries.category', 'revenue')
            ->where('ledger_entries.source_type', $ship)
            ->where('ledger_entries.occurred_at', '>=', $since)
            ->join('shipments', 'shipments.id', '=', 'ledger_entries.source_id')
            ->join('contracts', 'contracts.id', '=', 'shipments.contract_id');

        $byCommodity = (clone $revBase())
            ->join('commodities', 'commodities.id', '=', 'contracts.commodity_id')
            ->groupBy('commodities.name')
            ->selectRaw('commodities.name as label, SUM(ledger_entries.amount) as rev, COUNT(*) as n')
            ->orderByDesc('rev')->limit(10)->get()
            ->map(fn ($r) => ['label' => $r->label, 'revenue' => (int) $r->rev, 'n' => (int) $r->n]);

        $topRoutes = (clone $revBase())
            ->join('cities as o', 'o.id', '=', 'contracts.origin_city_id')
            ->join('cities as d', 'd.id', '=', 'contracts.destination_city_id')
            ->groupBy('o.name', 'd.name')
            ->selectRaw("o.name as origin, d.name as dest, SUM(ledger_entries.amount) as rev, COUNT(*) as n")
            ->orderByDesc('rev')->limit(8)->get()
            ->map(fn ($r) => ['label' => "{$r->origin} → {$r->dest}", 'revenue' => (int) $r->rev, 'n' => (int) $r->n]);

        $perVehicle = (clone $revBase())
            ->join('vehicles', 'vehicles.id', '=', 'shipments.vehicle_id')
            ->join('vehicle_models', 'vehicle_models.id', '=', 'vehicles.vehicle_model_id')
            ->groupBy('vehicles.id', 'vehicles.fleet_no', 'vehicles.nickname', 'vehicle_models.name')
            ->selectRaw('vehicles.fleet_no, vehicles.nickname, vehicle_models.name as model, SUM(ledger_entries.amount) as rev, COUNT(*) as n')
            ->orderByDesc('rev')->limit(10)->get()
            ->map(fn ($r) => [
                'label' => ($r->fleet_no ? '#'.str_pad((string) $r->fleet_no, 4, '0', STR_PAD_LEFT).' ' : '').($r->nickname ?: $r->model),
                'revenue' => (int) $r->rev, 'n' => (int) $r->n,
            ]);

        $perDriver = (clone $revBase())
            ->join('drivers', 'drivers.id', '=', 'shipments.driver_id')
            ->groupBy('drivers.id', 'drivers.crew_no', 'drivers.name')
            ->selectRaw('drivers.crew_no, drivers.name, SUM(ledger_entries.amount) as rev, COUNT(*) as n')
            ->orderByDesc('rev')->limit(10)->get()
            ->map(fn ($r) => [
                'label' => ($r->crew_no ? '#'.str_pad((string) $r->crew_no, 4, '0', STR_PAD_LEFT).' ' : '').$r->name,
                'revenue' => (int) $r->rev, 'n' => (int) $r->n,
            ]);

        // --- KPIs ----------------------------------------------------------
        $periodRevenue = (int) LedgerEntry::where('company_id', $company->id)
            ->where('category', 'revenue')->where('occurred_at', '>=', $since)->sum('amount');
        $periodExpense = -(int) LedgerEntry::where('company_id', $company->id)
            ->where('amount', '<', 0)->where('occurred_at', '>=', $since)->sum('amount');
        $periodNet = $periodRevenue - $periodExpense;

        // Distance & deliveries this period (by arrival).
        $arrived = DB::table('shipments')->where('company_id', $company->id)
            ->whereIn('status', ['delivered', 'late', 'failed'])
            ->where('arrived_at', '>=', $since);
        $kmDriven = (float) (clone $arrived)->sum('distance_km');
        $delivered = (clone $arrived)->whereIn('status', ['delivered', 'late'])->count();
        $onTime = (clone $arrived)->where('status', 'delivered')->count();
        $resolved = (clone $arrived)->count();

        $vehicleCount = Vehicle::where('company_id', $company->id)->count();
        $enRoute = Vehicle::where('company_id', $company->id)->where('status', 'en_route')->count();
        $driverCount = DB::table('drivers')->where('company_id', $company->id)->count();

        $cash = (int) $company->cash;
        $avgDailyExpense = $periodExpense / max(1, $since->diffInDays(now()));
        $equity = $cash + (int) Vehicle::where('company_id', $company->id)
            ->join('vehicle_models', 'vehicles.vehicle_model_id', '=', 'vehicle_models.id')
            ->sum('vehicle_models.price') - (int) $company->debt;

        $kpis = [
            'profit_margin' => $periodRevenue > 0 ? round($periodNet / $periodRevenue * 100, 1) : 0,
            'cost_per_km' => $kmDriven > 0 ? (int) round($periodExpense / $kmDriven) : 0,
            'revenue_per_truck' => $vehicleCount > 0 ? (int) round($periodRevenue / $vehicleCount) : 0,
            'revenue_per_driver' => $driverCount > 0 ? (int) round($periodRevenue / $driverCount) : 0,
            'avg_profit_per_delivery' => $delivered > 0 ? (int) round($periodNet / $delivered) : 0,
            'fleet_utilization' => $vehicleCount > 0 ? round($enRoute / $vehicleCount * 100, 0) : 0,
            'on_time_pct' => $resolved > 0 ? round($onTime / $resolved * 100, 0) : 0,
            'cash_runway_days' => $avgDailyExpense > 0 ? (int) round($cash / $avgDailyExpense) : null,
            'debt_to_equity' => $equity > 0 ? round((int) $company->debt / $equity, 2) : null,
            'deliveries' => $delivered,
            'km_driven' => (int) round($kmDriven),
        ];

        return response()->json([
            'period' => $period,
            'trend' => $trend,
            'expense_breakdown' => $breakdown,
            'by_commodity' => $byCommodity,
            'top_routes' => $topRoutes,
            'per_vehicle' => $perVehicle,
            'per_driver' => $perDriver,
            'kpis' => $kpis,
        ]);
    }

    /** Full transaction journal, filterable by category, newest first. */
    public function ledger(Request $request)
    {
        $company = $this->company($request);

        $entries = LedgerEntry::where('company_id', $company->id)
            ->when($request->query('category'), fn ($q, $c) => $q->where('category', $c))
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->paginate(40);

        return LedgerEntryResource::collection($entries);
    }
}
