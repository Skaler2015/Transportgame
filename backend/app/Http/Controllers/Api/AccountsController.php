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
