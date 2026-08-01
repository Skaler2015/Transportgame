<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesCompany;
use App\Http\Controllers\Controller;
use App\Models\Commodity;
use App\Models\Contract;
use App\Models\Driver;
use App\Models\ListedCompany;
use App\Models\MarketPrice;
use App\Models\Vehicle;
use Illuminate\Http\Request;

/**
 * In-game AI advisor. A rule-based analyst that turns the company's live state
 * and the market into a short list of actionable recommendations — deterministic
 * and cheap enough to compute on demand, no LLM required.
 */
class AdvisorController extends Controller
{
    use ResolvesCompany;

    public function index(Request $request)
    {
        $company = $this->company($request);

        $insights = array_filter([
            $this->bestContract($company),
            $this->bestArbitrage($company),
            $this->stockTip(),
            $this->opsAlert($company),
        ]);

        return response()->json(['data' => array_values($insights)]);
    }

    /** The richest job the current idle fleet can actually haul. */
    private function bestContract($company): ?array
    {
        $fleet = Vehicle::where('company_id', $company->id)
            ->where('status', Vehicle::STATUS_IDLE)->where('condition', '>', 15)
            ->with('model')->get();

        if ($fleet->isEmpty()) {
            return ['icon' => '🚚', 'title' => 'Free up a truck', 'detail' => 'No idle vehicle right now — a truck must be idle to take new work.'];
        }

        $best = Contract::onMarket()->with(['commodity', 'origin', 'destination'])
            ->whereHas('origin', fn ($q) => $q->where('country', $company->country))
            ->orderByDesc('payout')->limit(120)->get()
            ->first(function (Contract $c) use ($fleet) {
                $weight = $c->commodity->weight_per_unit * $c->units;

                return $fleet->contains(fn (Vehicle $v) => $c->commodity->canBeCarriedBy($v->model)
                    && $weight <= $v->effectiveCapacityWeight() + 0.001);
            });

        if (! $best) {
            return null;
        }

        $perKm = $best->distance_km > 0 ? round($best->payout / 100 / $best->distance_km, 1) : 0;

        return [
            'icon' => '💼',
            'title' => 'Top job for your fleet',
            'detail' => "{$best->commodity->name}: {$best->origin->name} → {$best->destination->name} pays ₹".
                number_format($best->payout / 100)." (₹{$perKm}/km).",
            'action' => ['label' => 'Contract Market', 'to' => '/contracts'],
        ];
    }

    /** The commodity with the widest price gap across the country. */
    private function bestArbitrage($company): ?array
    {
        $rows = MarketPrice::query()
            ->join('cities', 'cities.id', '=', 'market_prices.city_id')
            ->where('cities.country', $company->country)
            ->orderByDesc('market_prices.recorded_at')
            ->limit(2000)
            ->get(['market_prices.city_id', 'market_prices.commodity_id', 'market_prices.price', 'cities.name as city_name']);

        // Latest price per (commodity, city).
        $latest = [];
        foreach ($rows as $r) {
            $latest[$r->commodity_id][$r->city_id] ??= ['price' => (float) $r->price, 'city' => $r->city_name];
        }

        $best = null;
        foreach ($latest as $commodityId => $cities) {
            if (count($cities) < 2) {
                continue;
            }
            $lo = collect($cities)->sortBy('price')->first();
            $hi = collect($cities)->sortByDesc('price')->first();
            $spread = $hi['price'] - $lo['price'];
            if (! $best || $spread > $best['spread']) {
                $best = ['commodity_id' => $commodityId, 'lo' => $lo, 'hi' => $hi, 'spread' => $spread];
            }
        }

        if (! $best || $best['spread'] < 1) {
            return null;
        }

        $commodity = Commodity::find($best['commodity_id']);

        return [
            'icon' => '📦',
            'title' => 'Arbitrage opportunity',
            'detail' => ($commodity?->name ?? 'A commodity').": cheap in {$best['lo']['city']} (₹".number_format($best['lo']['price'], 0).
                "), dear in {$best['hi']['city']} (₹".number_format($best['hi']['price'], 0).
                ') — ~₹'.number_format($best['spread'], 0).'/unit spread.',
            'action' => ['label' => 'Warehouses', 'to' => '/warehouses'],
        ];
    }

    /** A listed firm trading below its usual level. */
    private function stockTip(): ?array
    {
        $dip = ListedCompany::orderByRaw('(share_price - base_price) / base_price ASC')->first();
        if (! $dip) {
            return null;
        }

        if ($dip->changePct() >= 0) {
            return ['icon' => '📈', 'title' => 'Markets are hot', 'detail' => 'Most listed firms trade above their usual price — hold, or take profits.', 'action' => ['label' => 'Stock Exchange', 'to' => '/stocks']];
        }

        return [
            'icon' => '📉',
            'title' => 'Possible buy',
            'detail' => "{$dip->name} is {$dip->changePct()}% below its usual price and yields ".
                round($dip->dividend_yield * 100, 1).'% — a possible value buy.',
            'action' => ['label' => 'Stock Exchange', 'to' => '/stocks'],
        ];
    }

    /** The most pressing operational risk. */
    private function opsAlert($company): ?array
    {
        $unlicensed = Driver::where('company_id', $company->id)
            ->where(fn ($q) => $q->whereNull('licence_until')->orWhere('licence_until', '<=', now()))
            ->count();
        if ($unlicensed > 0) {
            return ['icon' => '⚠️', 'title' => 'Licences expired', 'detail' => "{$unlicensed} driver(s) are driving without a valid licence — renew in Crew to cut accident risk.", 'action' => ['label' => 'Crew', 'to' => '/drivers']];
        }

        $lowFuel = Vehicle::where('company_id', $company->id)
            ->where('status', Vehicle::STATUS_IDLE)
            ->with('model')->get()
            ->filter(fn (Vehicle $v) => ($v->model->fuel_capacity ?? 0) > 0
                && $v->fuel < $v->model->fuel_capacity * 0.25)
            ->count();
        if ($lowFuel > 0) {
            return ['icon' => '⛽', 'title' => 'Refuel your fleet', 'detail' => "{$lowFuel} idle truck(s) are low on fuel. Use ‘Service & Fuel All’.", 'action' => ['label' => 'Fleet', 'to' => '/fleet']];
        }

        return ['icon' => '✅', 'title' => 'Fleet looks healthy', 'detail' => 'No urgent maintenance or licence issues right now. Keep the trucks rolling.'];
    }
}
