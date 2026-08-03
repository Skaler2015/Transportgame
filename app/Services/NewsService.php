<?php

namespace App\Services;

use App\Models\City;
use App\Models\NewsItem;
use App\Models\WorldEvent;
use Illuminate\Support\Facades\Cache;

/**
 * The live news wire. Turns world events and economic signals into a rolling
 * headline feed. Generation is rate-limited so it can run lazily on feed views
 * (the host cron isn't guaranteed) without spamming or thrashing the DB.
 */
class NewsService
{
    private const EVENT_ICON = [
        'fuel_crisis' => '⛽', 'festival' => '🎉', 'storm' => '⛈️',
        'boom' => '📈', 'embargo' => '🚫',
    ];

    private const SEVERITY_MAP = ['minor' => 'info', 'major' => 'warning', 'critical' => 'critical'];

    /** Generate any new headlines (rate-limited), for a player's country. */
    public function ensureFresh(?string $country = null): void
    {
        if (Cache::add('news:generate-lock', 1, now()->addSeconds(60))) {
            $this->generateFromEvents();
        }

        if ($country && Cache::add("news:econ:{$country}", 1, now()->addSeconds(600))) {
            $this->generateEconomic($country);
        }

        $this->prune();
    }

    /** One headline per active world event we haven't reported yet. */
    protected function generateFromEvents(): void
    {
        WorldEvent::active()->with('city')->get()->each(function (WorldEvent $event) {
            $exists = NewsItem::where('source_type', WorldEvent::class)
                ->where('source_id', $event->id)->exists();
            if ($exists) {
                return;
            }

            NewsItem::create([
                'country' => $event->city?->country,
                'category' => 'event',
                'severity' => self::SEVERITY_MAP[$event->severity] ?? 'info',
                'icon' => self::EVENT_ICON[$event->type] ?? '📰',
                'headline' => $event->title,
                'body' => $event->description,
                'source_type' => WorldEvent::class,
                'source_id' => $event->id,
                'occurred_at' => $event->starts_at ?? now(),
            ]);
        });
    }

    /** A flavour headline about the country's fuel market. */
    protected function generateEconomic(string $country): void
    {
        $dear = City::where('country', $country)->orderByDesc('fuel_price')->first();
        $cheap = City::where('country', $country)->orderBy('fuel_price')->first();
        if (! $dear || ! $cheap || $dear->id === $cheap->id) {
            return;
        }

        $symbol = config("transoria.country_currency.{$country}.symbol", '₹');

        NewsItem::create([
            'country' => $country,
            'category' => 'economy',
            'severity' => 'info',
            'icon' => '⛽',
            'headline' => "Fuel dearest in {$dear->name} at {$symbol}".number_format($dear->fuel_price, 2)."/L",
            'body' => "Cheapest fuel is in {$cheap->name} ({$symbol}".number_format($cheap->fuel_price, 2)
                .'/L). Plan long hauls to refuel where it's cheap.',
            'occurred_at' => now(),
        ]);
    }

    /** Keep the wire bounded. */
    protected function prune(): void
    {
        $cutoff = now()->subDays(3);
        NewsItem::where('occurred_at', '<', $cutoff)->delete();
    }

    /** Latest headlines relevant to a country (global + that country). */
    public function feed(?string $country, int $limit = 40)
    {
        return NewsItem::query()
            ->where(function ($q) use ($country) {
                $q->whereNull('country');
                if ($country) {
                    $q->orWhere('country', $country);
                }
            })
            ->orderByDesc('occurred_at')
            ->limit($limit)
            ->get();
    }
}
