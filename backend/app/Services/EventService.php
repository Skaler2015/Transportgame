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
        $def = $catalog[$type];

        [$minH, $maxH] = $def['duration_hours'];
        $regions = City::query()->distinct()->pluck('region');
        $scopeRegion = mt_rand() / mt_getrandmax() < 0.6 ? $regions->random() : null;

        $mods = $def['modifiers'];

        return WorldEvent::create([
            'type' => $type,
            'title' => $def['title'].($scopeRegion ? " — {$scopeRegion}" : ' (Continental)'),
            'description' => $this->describe($type, $scopeRegion),
            'severity' => $def['severity'],
            'region' => $scopeRegion,
            'price_modifier' => $mods['price_modifier'] ?? 1.0,
            'demand_modifier' => $mods['demand_modifier'] ?? 1.0,
            'fuel_modifier' => $mods['fuel_modifier'] ?? 1.0,
            'risk_modifier' => $mods['risk_modifier'] ?? 1.0,
            'starts_at' => now(),
            'ends_at' => now()->addHours(random_int($minH, $maxH)),
        ]);
    }

    /** Drift each city's weather and fuel price a little, nudged by events. */
    public function driftCities(Collection $events): void
    {
        $fuelDrift = config('transoria.economy.fuel_drift', 0.04);

        foreach (City::all() as $city) {
            // Weather occasionally changes.
            if (mt_rand() / mt_getrandmax() < 0.25) {
                $city->weather = self::WEATHERS[array_rand(self::WEATHERS)];
            }

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
