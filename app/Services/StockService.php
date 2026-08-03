<?php

namespace App\Services;

use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\ListedCompany;
use App\Models\ShareHolding;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A lightweight stock exchange. Share prices drift with a bounded random walk
 * plus mean-reversion to a reference price, advanced lazily (rate-limited) when
 * a player views the market so it lives without the world-tick cron. Holdings
 * pay dividends on a fixed cadence, also settled lazily.
 */
class StockService
{
    public function __construct(private readonly LedgerService $ledger) {}

    /** Advance prices (globally, rate-limited) and settle this company's dividends. */
    public function refresh(Company $company): void
    {
        $cfg = config('transoria.stocks');

        if (Cache::add('stocks:price-tick', 1, now()->addSeconds((int) $cfg['price_tick_seconds']))) {
            $this->walkPrices();
        }

        $this->settleDividends($company);
    }

    /** Bounded random walk with gentle reversion toward the base price. */
    protected function walkPrices(): void
    {
        $cfg = config('transoria.stocks');

        foreach (ListedCompany::all() as $lc) {
            $reversion = ($lc->base_price - $lc->share_price) / max(1, $lc->base_price) * 0.05;
            $shock = ((mt_rand() / mt_getrandmax()) * 2 - 1) * $lc->volatility;

            $price = $lc->share_price * (1 + $shock + $reversion);
            $price = max($lc->base_price * $cfg['price_floor_mult'], min($lc->base_price * $cfg['price_ceiling_mult'], $price));

            $lc->share_price = round($price, 2);

            // Keep a rolling window of recent prices for the sparkline/chart.
            $hist = $lc->price_history ?? [];
            $hist[] = round($price, 2);
            $lc->price_history = array_slice($hist, -30);

            $lc->save();
        }
    }

    /** Pay any holding whose dividend cycle has elapsed. */
    protected function settleDividends(Company $company): void
    {
        $minutes = (int) config('transoria.stocks.dividend_interval_minutes', 20);
        $cutoff = now()->subMinutes($minutes);

        $holdings = ShareHolding::where('company_id', $company->id)
            ->where('shares', '>', 0)
            ->where(function ($q) use ($cutoff) {
                $q->whereNull('last_dividend_at')->orWhere('last_dividend_at', '<=', $cutoff);
            })
            ->with('listedCompany')
            ->get();

        foreach ($holdings as $holding) {
            $lc = $holding->listedCompany;
            if (! $lc) {
                continue;
            }
            $payout = (int) round($holding->shares * $lc->share_price * $lc->dividend_yield * 100);
            if ($payout > 0) {
                $this->ledger->post($company, LedgerEntry::CAT_REVENUE,
                    "Dividend: {$lc->name}", $payout);
            }
            $holding->last_dividend_at = now();
            $holding->save();
        }
    }

    /** Buy shares of a listed company at the current price. */
    public function buy(Company $company, ListedCompany $listed, int $shares): ShareHolding
    {
        if ($shares < 1) {
            throw new RuntimeException('Enter at least 1 share.');
        }

        $cost = (int) round($shares * $listed->share_price * 100);
        if ($company->cash < $cost) {
            throw new RuntimeException('Not enough cash for this purchase.');
        }

        return DB::transaction(function () use ($company, $listed, $shares, $cost) {
            $holding = ShareHolding::firstOrNew([
                'company_id' => $company->id,
                'listed_company_id' => $listed->id,
            ]);

            $existingValue = $holding->exists ? $holding->avg_cost * $holding->shares : 0;
            $newShares = (int) ($holding->shares ?? 0) + $shares;
            $holding->shares = $newShares;
            $holding->avg_cost = round(($existingValue + $listed->share_price * $shares) / max(1, $newShares), 2);
            $holding->last_dividend_at ??= now();
            $holding->save();

            $this->ledger->post($company, LedgerEntry::CAT_PURCHASE,
                "Bought {$shares} {$listed->key} shares", -$cost, $holding);

            return $holding;
        });
    }

    /** Sell shares back to the market at the current price. */
    public function sell(Company $company, ListedCompany $listed, int $shares): array
    {
        if ($shares < 1) {
            throw new RuntimeException('Enter at least 1 share.');
        }

        $holding = ShareHolding::where('company_id', $company->id)
            ->where('listed_company_id', $listed->id)->first();

        if (! $holding || $holding->shares < $shares) {
            throw new RuntimeException('You do not hold that many shares.');
        }

        $gross = (int) round($shares * $listed->share_price * 100);

        return DB::transaction(function () use ($company, $listed, $shares, $gross, $holding) {
            $holding->shares -= $shares;
            $holding->shares <= 0 ? $holding->delete() : $holding->save();

            $this->ledger->post($company, LedgerEntry::CAT_REVENUE,
                "Sold {$shares} {$listed->key} shares", $gross, $company);

            return ['shares' => $shares, 'gross' => $gross];
        });
    }

    /** Current market value of a company's whole portfolio (₡ cents). */
    public function portfolioValueCents(Company $company): int
    {
        $total = 0.0;
        $holdings = ShareHolding::where('company_id', $company->id)->with('listedCompany')->get();
        foreach ($holdings as $h) {
            $total += $h->shares * ($h->listedCompany->share_price ?? 0);
        }

        return (int) round($total * 100);
    }
}
