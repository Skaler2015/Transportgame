<?php

namespace App\Services;

use App\Models\City;
use App\Models\WorldEvent;
use Illuminate\Support\Collection;

/**
 * Rolls the "living world": spawns time-boxed macro events (fuel crises,
 * festivals, storms) and drifts city weather & fuel prices each tick.
 */
class EventService
{
    private const WEATHERS = ['clear', 'clear', 'clear', 'rain', 'fog', 'heat', 'snow', 'storm'];

    /** Currently active events. */
    public function active(): Collection
    {
        return WorldEvent::active()->get();
    }

    /**
     * Maybe spawn one new world event this tick (probabilistic). Returns the
     * event created, or null.
     */
    public function maybeSpawn(float $chance = 0.35): ?WorldEvent
    {
        if (mt_rand() / mt_getrandmax() > $chance) {
            return null;
        }

        $catalog = config('transoria.events');
        $weighted = [];
        foreach ($catalog as $type => $def) {
            $weighted = array_merge($weighted, array_fill(0, $def['weight'], $type));
        }
        $type = $weighted[array_rand($weighted)];

        [$minH, $maxH] = $catalog[$type]['duration_hours'];
        $regions = City::query()->distinct()->pluck('region');
        $scopeRegion = mt_rand() / mt_getrandmax() < 0.6 ? $regions->random() : null;

        return $this->spawn($type, $scopeRegion, random_int($minH, $maxH));
    }

    /**
     * Spawn a specific event type (used by the admin panel and by maybeSpawn).
     * A null region is continental (world-wide). Returns the created event, or
     * null if the type is unknown.
     */
    public function spawn(string $type, ?string $region = null, ?int $hours = null): ?WorldEvent
    {
        $def = config('transoria.events.'.$type);
        if (! $def) {
            return null;
        }

        [$minH, $maxH] = $def['duration_hours'];
        $hours ??= random_int($minH, $maxH);
        $mods = $def['modifiers'];

        return WorldEvent::create([
            'type' => $type,
            'title' => $def['title'].($region ? " — {$region}" : ' (Continental)'),
            'description' => $this->describe($type, $region),
            'severity' => $def['severity'],
            'region' => $region,
            'price_modifier' => $mods['price_modifier'] ?? 1.0,
            'demand_modifier' => $mods['demand_modifier'] ?? 1.0,
            'fuel_modifier' => $mods['fuel_modifier'] ?? 1.0,
            'risk_modifier' => $mods['risk_modifier'] ?? 1.0,
            'starts_at' => now(),
            'ends_at' => now()->addHours(max(1, $hours)),
        ]);
    }

    /** Drift each city's weather and fuel price a little, nudged by events. */
    public function driftCities(Collection $events): void
    {
        $fuelDrift = config('transoria.economy.fuel_drift', 0.04);
        $gen = config('transoria.weather_gen');
        $bag = $this->seasonWeatherBag($gen);
        $prevailing = $this->regionalPrevailing($bag);
        $persistence = (float) ($gen['persistence'] ?? 0.68);
        $pull = (float) ($gen['regional_pull'] ?? 0.6);

        foreach (City::all() as $city) {
            // 1) A weather event over this city forces harsh conditions.
            $forced = $this->eventWeather($city, $events, $gen);
            if ($forced) {
                $city->weather = $forced;
            } elseif (mt_rand() / mt_getrandmax() >= $persistence) {
                // 2) Otherwise re-roll: usually match the region's prevailing
                //    weather (coherence), sometimes draw fresh from the season.
                $city->weather = (mt_rand() / mt_getrandmax() < $pull)
                    ? ($prevailing[$city->region] ?? $this->pickWeighted($bag))
                    : $this->pickWeighted($bag);
            }
            // 3) else: keep current weather (persistence).

            // Fuel price random walk within a band, amplified by fuel events.
            $eventFuel = 1.0;
            foreach ($events as $event) {
                if (! $event->city_id || $event->city_id === $city->id) {
                    if (! $event->region || $event->region === $city->region) {
                        $eventFuel *= $event->fuel_modifier;
                    }
                }
            }

            $walk = 1 + (mt_rand() / mt_getrandmax() * 2 - 1) * $fuelDrift;
            $city->fuel_price = round(max(0.6, min(3.0, $city->fuel_price * $walk * (1 + ($eventFuel - 1) * 0.15))), 2);

            // Traffic breathes.
            $city->traffic = max(5, min(95, $city->traffic + random_int(-6, 6)));

            $city->save();
        }
    }

    /** The weighted weather bag for the current calendar month. */
    private function seasonWeatherBag(array $gen): array
    {
        $season = $gen['month_season'][(int) now()->month] ?? 'default';

        return $gen['season_bags'][$season] ?? $gen['season_bags']['default'];
    }

    /** One prevailing weather per region for the day (stable, region-coherent). */
    private function regionalPrevailing(array $bag): array
    {
        $today = now()->format('Ymd');
        $out = [];
        foreach (City::query()->distinct()->pluck('region') as $region) {
            // Deterministic per region+day so a region trends together.
            $out[$region] = $this->pickWeighted($bag, crc32($region.':'.$today));
        }

        return $out;
    }

    /** A weather-type event covering this city forces its weather, else null. */
    private function eventWeather(City $city, Collection $events, array $gen): ?string
    {
        $map = $gen['event_weather'] ?? [];
        foreach ($events as $event) {
            if (! isset($map[$event->type])) {
                continue;
            }
            $covers = (! $event->city_id || $event->city_id === $city->id)
                && (! $event->region || $event->region === $city->region);
            if ($covers) {
                return $map[$event->type];
            }
        }

        return null;
    }

    /** Weighted pick from [key => weight]; deterministic when a seed is given. */
    private function pickWeighted(array $bag, ?int $seed = null): string
    {
        $total = array_sum($bag);
        if ($total <= 0) {
            return 'clear';
        }
        $roll = $seed === null ? mt_rand(1, $total) : ($seed % $total) + 1;
        foreach ($bag as $key => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return $key;
            }
        }

        return array_key_first($bag);
    }

    private function describe(string $type, ?string $region): string
    {
        $where = $region ? "the {$region} region" : 'the whole Transoria belt';

        return match ($type) {
            'fuel_crisis' => "Refinery disruptions have sent fuel prices soaring across {$where}.",
            'festival' => "A harvest festival is driving a demand surge across {$where}.",
            'storm' => "A severe storm front is raising road risk across {$where}.",
            'boom' => "An industrial boom is lifting demand across {$where}.",
            'embargo' => "A trade embargo is squeezing supply and inflating prices across {$where}.",
            default => "An economic shift is underway across {$where}.",
        };
    }
}
