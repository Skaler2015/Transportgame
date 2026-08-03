<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesCompany;
use App\Http\Controllers\Controller;
use App\Models\LedgerEntry;
use App\Models\ListedCompany;
use App\Models\ShareHolding;
use App\Services\StockService;
use Illuminate\Http\Request;
use RuntimeException;

class StockController extends Controller
{
    use ResolvesCompany;

    public function __construct(private readonly StockService $stocks) {}

    /** The exchange plus the player's portfolio. */
    public function index(Request $request)
    {
        $company = $this->company($request);
        $this->stocks->refresh($company);

        $holdings = ShareHolding::where('company_id', $company->id)
            ->where('shares', '>', 0)->get()->keyBy('listed_company_id');

        $rows = ListedCompany::orderBy('name')->get()->map(function (ListedCompany $lc) use ($holdings) {
            $h = $holdings->get($lc->id);

            return [
                'id' => $lc->id,
                'key' => $lc->key,
                'name' => $lc->name,
                'sector' => $lc->sector,
                'share_price' => round($lc->share_price, 2),
                'change_pct' => $lc->changePct(),
                'dividend_yield' => $lc->dividend_yield,
                'shares_held' => $h ? (int) $h->shares : 0,
                'avg_cost' => $h ? (float) $h->avg_cost : 0,
                'position_value' => $h ? round($h->shares * $lc->share_price, 2) : 0,
                'history' => array_map(fn ($p) => round((float) $p, 2), $lc->price_history ?? []),
            ];
        });

        $portfolioCents = $this->stocks->portfolioValueCents($company);
        // Cost basis of open positions (cents) and lifetime dividends received.
        $investedCents = (int) round((float) ShareHolding::where('company_id', $company->id)
            ->where('shares', '>', 0)->get()->sum(fn ($h) => $h->shares * $h->avg_cost) * 100);
        $dividendsCents = (int) LedgerEntry::where('company_id', $company->id)
            ->where('description', 'like', 'Dividend:%')->sum('amount');

        return response()->json([
            'data' => $rows,
            'portfolio_value' => $portfolioCents,
            'invested' => $investedCents,
            'dividends_earned' => $dividendsCents,
        ]);
    }

    public function buy(Request $request, ListedCompany $listed)
    {
        return $this->trade($request, $listed, 'buy');
    }

    public function sell(Request $request, ListedCompany $listed)
    {
        return $this->trade($request, $listed, 'sell');
    }

    private function trade(Request $request, ListedCompany $listed, string $action)
    {
        $company = $this->company($request);
        $data = $request->validate(['shares' => ['required', 'integer', 'min:1', 'max:1000000']]);

        try {
            $this->stocks->$action($company, $listed, $data['shares']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => ucfirst($action).' order filled.']);
    }
}
