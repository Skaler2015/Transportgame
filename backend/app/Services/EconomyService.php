<?php

namespace App\Services;

use App\Models\City;
use App\Models\Commodity;
use App\Models\Contract;
use App\Models\MarketPrice;
use App\Models\WorldEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The beating heart of Transoria's world: turns supply, demand, stock and
 * active world-events into live local prices, and mints the haulage contracts
 * players compete over. All numeric behaviour is driven by config/transoria.php.
 */
class EconomyService
{
    /**
     * Compute the current local price (in ₡, not cents) for a commodity in a
     * city given a stock level and the currently active world events.
     *
     * @param  Collection<int,WorldEvent>  $events
     * @return array{price: float, demand_index: float}
     */
    public function localPrice(City $city, Commodity $commodity, int $production, int $consumption, int $stock, Collection $events): array
    {
        $cfg = config('transoria.economy');

        // Shortage signal: demand relative to what's actually available to buy.
        // Producers (high production, deep stock) trend below 1; starved
        // consumers (no production, thin stock) spike above 1.
        $availability = $production + $stock * 0.10 + 1;
        $raw = ($consumption + 1) / $availability;

        // Squash into a sane band before elasticity is applied.
        $demandIndex = max(0.35, min(2.4, $raw));

        // Seasonal swing: the calendar shifts whole-category demand.
        $demandIndex *= $this->seasonalDemand($commodity->category);

        // Richer, faster-growing cities consume a little more eagerly.
        if ($city->gdp_per_capita) {
            $demandIndex *= 1 + min(0.15, max(-0.05, ($city->gdp_per_capita - 300_000) / 3_000_000));
        }

        // Apply demand modifiers from events scoped to this city/commodity/region.
        foreach ($events as $event) {
            if ($this->eventApplies($event, $city, $commodity)) {
                $demandIndex *= $event->demand_modifier;
            }
        }

        $price = $commodity->base_price * (1 + $cfg['price_elasticity'] * ($demandIndex - 1));

        foreach ($events as $event) {
            if ($this->eventApplies($event, $city, $commodity)) {
                $price *= $event->price_modifier;
            }
        }

        $floor = $commodity->base_price * $cfg['price_floor_pct'];
        $ceiling = $commodity->base_price * $cfg['price_ceiling_pct'];
        $price = max($floor, min($ceiling, $price));

        return [
            'price' => round($price, 2),
            'demand_index' => round($demandIndex, 3),
        ];
    }

    /** Demand multiplier for a commodity category in the current season. */
    public function seasonalDemand(string $category): float
    {
        $season = $this->currentSeason();

        return $season ? (float) ($season['demand'][$category] ?? 1.0) : 1.0;
    }

    /** The active season definition for the current month, or null. */
    public function currentSeason(): ?array
    {
        $month = (int) now()->month;
        foreach (config('transoria.seasons', []) as $season) {
            if (in_array($month, $season['months'] ?? [], true)) {
                return $season;
            }
        }

        return null;
    }

    /** Does an event's scope cover this city/commodity? Null scopes are global. */
    public function eventApplies(WorldEvent $event, City $city, ?Commodity $commodity = null): bool
    {
        if ($event->city_id && $event->city_id !== $city->id) {
            return false;
        }
        if ($event->region && $event->region !== $city->region) {
            return false;
        }
        if ($event->commodity_id && $commodity && $event->commodity_id !== $commodity->id) {
            return false;
        }

        return true;
    }

    /**
     * Advance every city/commodity market by one tick: move stock, recompute
     * price, and append a snapshot to the price history. Returns rows written.
     */
    public function tickMarkets(Collection $events): int
    {
        $now = now();
        $written = 0;

        $rows = DB::table('city_commodity')->get();
        $cities = City::all()->keyBy('id');
        $commodities = Commodity::all()->keyBy('id');

        $snapshots = [];

        foreach ($rows as $row) {
            $city = $cities[$row->city_id] ?? null;
            $commodity = $commodities[$row->commodity_id] ?? null;
            if (! $city || ! $commodity) {
                continue;
            }

            // Move inventory: production adds, consumption drains, clamped.
            $newStock = max(0, min($row->stock_cap, $row->stock + $row->production - $row->consumption));

            $result = $this->localPrice($city, $commodity, $row->production, $row->consumption, $newStock, $events);

            DB::table('city_commodity')->where('id', $row->id)->update(['stock' => $newStock]);

            $snapshots[] = [
                'city_id' => $row->city_id,
                'commodity_id' => $row->commodity_id,
                'price' => $result['price'],
                'demand_index' => $result['demand_index'],
                'recorded_at' => $now,
            ];
            $written++;
        }

        foreach (array_chunk($snapshots, 200) as $chunk) {
            MarketPrice::insert($chunk);
        }

        // Prune history to keep the series bounded per city/commodity.
        $this->pruneHistory();

        return $written;
    }

    /** Keep only the most recent N snapshots per city/commodity pair. */
    protected function pruneHistory(): void
    {
        $keep = config('transoria.tick.price_history_keep', 96);

        // Cheap global prune: drop rows older than keep * tick minutes.
        $cutoff = now()->subMinutes($keep * config('transoria.tick.minutes', 15) * 2);
        MarketPrice::where('recorded_at', '<', $cutoff)->delete();
    }

    /** Latest recorded price for a city/commodity, falling back to base. */
    public function latestPrice(int $cityId, Commodity $commodity): float
    {
        $row = MarketPrice::where('city_id', $cityId)
            ->where('commodity_id', $commodity->id)
            ->orderByDesc('recorded_at')
            ->first();

        return $row?->price ?? $commodity->base_price;
    }

    /**
     * Refill the open-contract market so each producing city keeps roughly
     * `target_open_per_hub` live offers. Returns the number created.
     */
    public function replenishContracts(Collection $events): int
    {
        $cfg = config('transoria.contracts');
        $created = 0;

        // Retire any open jobs below the current payout floor so the market
        // only ever shows contracts worth at least the minimum.
        if (! empty($cfg['min_payout'])) {
            Contract::where('status', Contract::STATUS_OPEN)
                ->where('payout', '<', (int) $cfg['min_payout'])
                ->update(['status' => Contract::STATUS_EXPIRED]);
        }

        // Retire any open job that would run at a loss (its payout no longer
        // clears its lane's tolls/tax/fuel) — e.g. contracts minted under an
        // older pricing rule — so the board only shows profitable work.
        $lossmakers = Contract::where('status', Contract::STATUS_OPEN)
            ->whereNull('company_id')
            ->with(['origin:id,toll_per_km,fuel_price', 'destination:id,toll_per_km,tax_rate'])
            ->get()
            ->filter(function (Contract $c) {
                if (! $c->origin || ! $c->destination) {
                    return false;
                }
                $avgToll = (($c->origin->toll_per_km ?? 0) + ($c->destination->toll_per_km ?? 0)) / 2;
                $tollExpense = $c->distance_km * $avgToll;
                $fuelExpense = $c->distance_km * 0.30 * ($c->origin->fuel_price ?? 1.0);
                $denom = max(0.15, 1 - (float) ($c->destination->tax_rate ?? 0) - 0.20);
                $minProfitable = (($tollExpense + $fuelExpense) / $denom) * 100; // cents

                return $c->payout < $minProfitable * 0.95;
            })
            ->pluck('id');

        if ($lossmakers->isNotEmpty()) {
            Contract::whereIn('id', $lossmakers)->update(['status' => Contract::STATUS_EXPIRED]);
        }

        $commodities = Commodity::all()->keyBy('id');
        $cities = City::all();

        // Precompute a latest-price lookup: [commodity_id][city_id] => price.
        $priceLookup = [];
        foreach (MarketPrice::query()
            ->select('city_id', 'commodity_id', 'price', 'recorded_at')
            ->orderByDesc('recorded_at')
            ->get() as $mp) {
            $priceLookup[$mp->commodity_id][$mp->city_id] ??= $mp->price;
        }

        // Cities grouped by country — contracts stay domestic (origin and
        // destination in the same country).
        $byCountry = $cities->groupBy('country');

        // For each producing (city, commodity) pair, ensure enough open offers.
        $pairs = DB::table('city_commodity')->where('production', '>', 0)->get();

        foreach ($pairs as $pair) {
            $commodity = $commodities[$pair->commodity_id] ?? null;
            $origin = $cities->firstWhere('id', $pair->city_id);
            if (! $commodity || ! $origin || ! $origin->country) {
                continue; // skip cities not attached to a playable country
            }

            $domestic = $byCountry[$origin->country] ?? collect();
            if ($domestic->count() < 2) {
                continue;
            }

            $openCount = Contract::where('status', Contract::STATUS_OPEN)
                ->where('origin_city_id', $origin->id)
                ->where('commodity_id', $commodity->id)
                ->where('expires_at', '>', now())
                ->count();

            $need = max(0, (int) ceil($cfg['target_open_per_hub'] / 3) - $openCount);

            for ($i = 0; $i < $need; $i++) {
                $contract = $this->mintContract($origin, $commodity, $domestic, $priceLookup, $events);
                if ($contract) {
                    $created++;
                }
            }
        }

        return $created;
    }

    /**
     * Create one contract hauling $commodity from $origin to the most
     * profitable reachable consumer city. Returns the Contract or null.
     */
    protected function mintContract(City $origin, Commodity $commodity, Collection $cities, array $priceLookup, Collection $events): ?Contract
    {
        $cfg = config('transoria.contracts');
        $cfgShip = config('transoria.shipment');
        $originPrice = $priceLookup[$commodity->id][$origin->id] ?? $commodity->base_price;

        // Find the best consumer: highest local price, not the origin.
        $best = null;
        $bestSpread = 0;
        foreach ($cities as $dest) {
            if ($dest->id === $origin->id) {
                continue;
            }
            $destPrice = $priceLookup[$commodity->id][$dest->id] ?? $commodity->base_price;
            $spread = $destPrice - $originPrice;
            if ($spread > $bestSpread) {
                $bestSpread = $spread;
                $best = $dest;
            }
        }

        // No profitable lane — occasionally still offer a break-even local run.
        if (! $best) {
            $candidates = $cities->where('id', '!=', $origin->id);
            if ($candidates->isEmpty()) {
                return null;
            }
            $best = $candidates->random();
        }

        $distance = $origin->distanceTo($best);

        // Size the load to fit a spread of vehicle classes. We pick a target
        // tonnage band, then derive unit count from the commodity's unit weight
        // so heavy goods yield few units and light goods yield many — and so
        // there are always small loads a starter truck can actually haul.
        // Weighted toward smaller loads so an early-game starter truck always
        // has plenty of contracts it can physically carry.
        $bands = [
            [0.5, 1.1], [0.5, 1.1], [0.5, 1.1],
            [1.5, 3.4], [1.5, 3.4], [1.5, 3.4],
            [4.0, 9.0], [10.0, 20.0], [20.0, 26.0],
        ];
        [$lo, $hi] = $bands[array_rand($bands)];
        $targetTonnes = $lo + (mt_rand() / mt_getrandmax()) * ($hi - $lo);
        $units = max(1, (int) round($targetTonnes / max(0.05, $commodity->weight_per_unit)));

        $isRush = random_int(1, 100) <= 22;

        // Difficulty from distance, risk, hazmat.
        $difficulty = 1;
        $difficulty += $distance > 500 ? 1 : 0;
        $difficulty += $commodity->risk > 35 ? 1 : 0;
        $difficulty += $commodity->is_hazardous ? 1 : 0;
        $difficulty += $isRush ? 1 : 0;
        $difficulty = min(5, $difficulty);

        // Freight is priced PER KILOMETRE at the configured ₹/km — EVERY job pays
        // at least that base rate. A bigger load pays a bonus on top (up to 4×);
        // it never pays less than the flat rate for a small parcel.
        $rateLo = (float) ($cfg['rate_per_km_min'] ?? 5.0);
        $rateHi = (float) ($cfg['rate_per_km_max'] ?? 5.0);
        $ratePerKm = $rateLo + (mt_rand() / mt_getrandmax()) * ($rateHi - $rateLo);

        // 1× for anything up to ~6 t, rising to 4× for a full 24 t truckload.
        $loadBonus = max(1.0, min(4.0, $targetTonnes / 6.0));

        $payout = $distance * $ratePerKm * $loadBonus;
        if ($isRush) {
            $payout *= 1 + $cfg['rush_margin_bonus'];
        }
        $payout *= 1 + ($difficulty - 1) * 0.06;

        // GUARANTEE the job clears its own running costs. Tolls (and, to a lesser
        // extent, tax + fuel) can eat a long haul; bump the payout so it always
        // leaves a healthy net margin — no contract is ever a loss.
        $avgToll = (($origin->toll_per_km ?? 0) + ($best->toll_per_km ?? 0)) / 2;
        $tollExpense = $distance * $avgToll;
        $fuelExpense = $distance * 0.30 * ($origin->fuel_price ?? 1.0); // representative truck
        // Per-trip upkeep auto-charged on arrival (repair + tyres + oil + battery),
        // in ₹ — costs are stored in cents so divide by 100.
        $g = config('transoria.garage');
        $upkeepExpense = $distance / 1000 * (
            $cfgShip['condition_loss_per_1000km'] * ($g['repair_cost_per_point'] / 100)
            + $cfgShip['tire_loss_per_1000km'] * ($g['tire_cost_per_point'] / 100)
            + ($g['oil_loss_per_1000km'] / 100) * ($g['oil_change_cost'] / 100)
            + ($g['battery_loss_per_1000km'] / 100) * ($g['battery_cost'] / 100)
        );
        $taxRate = (float) ($best->tax_rate ?? 0);
        $marginTarget = 0.20; // want ≥20% net after tax, tolls, fuel & upkeep
        $denom = max(0.15, 1 - $taxRate - $marginTarget);
        $minProfitable = ($tollExpense + $fuelExpense + $upkeepExpense) / $denom;
        $payout = max($payout, $minProfitable);

        $payoutCents = (int) round($payout * 100);
        // No job pays under the configured floor.
        $payoutCents = max((int) ($cfg['min_payout'] ?? 0), $payoutCents);
        $penaltyCents = (int) round($payoutCents * $cfg['penalty_pct']);

        // Deadline: ideal time at reference speed, padded by slack.
        $idealHours = $distance / $cfg['deadline_speed_kmh'];
        $secondsPerGameHour = config('transoria.tick.seconds_per_game_hour', 60);
        $deadlineSeconds = $idealHours * $cfg['deadline_slack'] * $secondsPerGameHour;

        return Contract::create([
            'commodity_id' => $commodity->id,
            'origin_city_id' => $origin->id,
            'destination_city_id' => $best->id,
            'company_id' => null,
            'status' => Contract::STATUS_OPEN,
            'units' => $units,
            'distance_km' => $distance,
            'payout' => $payoutCents,
            'penalty' => $penaltyCents,
            'reputation_reward' => 3 + $difficulty * 2,
            'difficulty' => $difficulty,
            'is_rush' => $isRush,
            'is_fragile' => (bool) ($commodity->is_perishable || in_array($commodity->key, ['electronics', 'luxury_goods', 'solar_panels', 'automobiles'], true)),
            'deadline_at' => now()->addSeconds((int) max(120, $deadlineSeconds)),
            'expires_at' => now()->addHours($cfg['ttl_hours']),
        ]);
    }

    /** Expire stale open contracts that were never claimed. */
    public function expireStaleContracts(): int
    {
        return Contract::where('status', Contract::STATUS_OPEN)
            ->where('expires_at', '<=', now())
            ->update(['status' => Contract::STATUS_EXPIRED]);
    }
}
