<?php

namespace App\Services;

use App\Models\City;
use App\Models\Company;
use App\Models\NewsItem;
use App\Models\User;
use App\Models\WorldEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Autonomous rival logistics firms — Phase 1 of the "living world".
 *
 * A rival is an ordinary `companies` row flagged `is_ai`, owned by one shared
 * system user, whose fleet is a single `ai_fleet_size` counter rather than a
 * table of Vehicle rows. That keeps a whole roster of competitors nearly free
 * on shared hosting while still letting them show up on the leaderboard, hold
 * market share, expand, and go bankrupt right alongside human players.
 *
 * Rivals advance in discrete GAME-HOURS (one every {@see GAME_HOUR_SECONDS}
 * real seconds) during the lazy world tick. Each hour's outcome is derived
 * deterministically from crc32(id:hour), so a burst of catch-up after a quiet
 * period reproduces exactly the same history it would have had if stepped live.
 */
class AiCompanyService
{
    /** Real seconds that make up one simulated game-hour of rival activity. */
    public const GAME_HOUR_SECONDS = 300; // 5 real minutes → 1 game hour

    public const SYSTEM_EMAIL = 'world@transoria.ai';

    public function __construct(
        protected EconomyService $economy,
    ) {}

    /**
     * Lazy entry point, called from request hooks. Rate-limited so only one
     * request every few seconds does the work; everyone else sails through.
     * Keeps each active country's roster full and steps every rival forward to
     * the current game-hour (bounded catch-up).
     */
    public function stepDue(?string $country = null): void
    {
        $cooldown = (int) config('transoria.ai.step_cooldown_seconds', 20);
        if (! Cache::add('ai:step-lock', 1, now()->addSeconds($cooldown))) {
            return;
        }

        // Refill rosters for the default country plus wherever humans are playing.
        $countries = Company::human()
            ->whereNotNull('headquarters_city_id')
            ->join('cities', 'companies.headquarters_city_id', '=', 'cities.id')
            ->distinct()->pluck('cities.country')->filter()->values()->all();
        $countries[] = $country ?? config('transoria.default_country', 'IN');

        foreach (array_unique($countries) as $c) {
            $this->ensureRoster($c);
        }

        $this->stepAll();
    }

    /** Current absolute game-hour index (monotonic, derived from wall clock). */
    public function currentHour(): int
    {
        return intdiv(now()->getTimestamp(), self::GAME_HOUR_SECONDS);
    }

    /** The shared user that owns every AI company. */
    public function systemUser(): User
    {
        return User::firstOrCreate(
            ['email' => self::SYSTEM_EMAIL],
            ['name' => 'Transoria World', 'password' => bcrypt(Str::random(40))],
        );
    }

    /**
     * Ensure a country has its target number of live rivals, founding fresh
     * firms to top it up. Cheap no-op once the roster is full.
     */
    public function ensureRoster(string $country): int
    {
        $target = (int) config('transoria.ai.roster_size', 24);

        $cityIds = City::where('country', $country)->pluck('id');
        if ($cityIds->isEmpty()) {
            return 0;
        }

        $live = Company::ai()->whereNull('ai_bankrupt_at')
            ->whereIn('headquarters_city_id', $cityIds)->count();

        $founded = 0;
        for ($i = $live; $i < $target; $i++) {
            $this->found($country, $cityIds);
            $founded++;
        }

        return $founded;
    }

    /** Found one believable rival with a seeded endowment and strategy. */
    public function found(string $country, ?Collection $cityIds = null): Company
    {
        $cityIds ??= City::where('country', $country)->pluck('id');
        $hqId = $cityIds->random();
        $strategy = $this->pickStrategy();

        [$cashLo, $cashHi] = config('transoria.ai.seed_cash', [180000_00, 900000_00]);
        [$fleetLo, $fleetHi] = config('transoria.ai.seed_fleet', [3, 14]);
        [$repLo, $repHi] = config('transoria.ai.seed_reputation', [420, 760]);

        $name = $this->uniqueName();

        return Company::create([
            'user_id' => $this->systemUser()->id,
            'is_ai' => true,
            'ai_strategy' => $strategy,
            'ai_fleet_size' => random_int($fleetLo, $fleetHi),
            'ai_state' => ['loss_streak' => 0, 'last_hour' => $this->currentHour(), 'founded_hour' => $this->currentHour()],
            'ai_bankrupt_at' => null,
            'name' => $name,
            'slug' => $this->uniqueSlug($name),
            'logo_color' => $this->randomColor(),
            'motto' => 'Moving '.$country.' forward.',
            'headquarters_city_id' => $hqId,
            'cash' => random_int((int) $cashLo, (int) $cashHi),
            'reputation' => random_int($repLo, $repHi),
            'level' => 1,
        ]);
    }

    /** Advance every live rival to the current game-hour (bounded catch-up). */
    public function stepAll(): void
    {
        $maxHours = (int) config('transoria.ai.max_catchup_hours', 12);
        $nowHour = $this->currentHour();
        $demand = $this->worldDemandFactor();

        Company::ai()->whereNull('ai_bankrupt_at')->orderBy('id')
            ->chunkById(100, function ($batch) use ($nowHour, $maxHours, $demand) {
                foreach ($batch as $company) {
                    $this->stepCompany($company, $nowHour, $maxHours, $demand);
                }
            });
    }

    /** Run one rival forward from its stored hour up to $nowHour (capped). */
    protected function stepCompany(Company $company, int $nowHour, int $maxHours, float $demand): void
    {
        $state = $company->ai_state ?? [];
        $last = (int) ($state['last_hour'] ?? $nowHour - 1);
        $hours = max(0, min($nowHour - $last, $maxHours));
        if ($hours === 0) {
            return;
        }

        for ($h = $last + 1; $h <= $last + $hours; $h++) {
            if ($this->applyHour($company, $h, $demand, $state)) {
                // Rival folded and was refounded in place — its state is fresh.
                return;
            }
        }

        $state['last_hour'] = $last + $hours;
        $company->ai_state = $state;
        $company->last_tick_at = now();
        $company->save();
    }

    /**
     * Simulate a single game-hour. Returns true if the company went bankrupt
     * (and was refounded), so the caller stops stepping the old identity.
     */
    protected function applyHour(Company $company, int $hour, float $demand, array &$state): bool
    {
        $strat = $this->strategy($company->ai_strategy);
        $cfg = config('transoria.ai');

        $fleet = max(0, (int) $company->ai_fleet_size);
        $jitter = 0.82 + 0.36 * $this->rand01($company->id, $hour);
        $repFactor = 0.85 + ($company->reputation / 1000) * 0.30;

        $revenue = (int) round($fleet * $cfg['revenue_per_truck_hour'] * $strat['revenue'] * $demand * $repFactor * $jitter);
        $cost = (int) round($fleet * $cfg['cost_per_truck_hour'] * $strat['cost'] + $cfg['overhead_per_hour']);
        $profit = $revenue - $cost;

        $company->cash += $profit;
        $company->lifetime_revenue += $revenue;
        $company->lifetime_expenses += $cost;
        $company->shipments_completed += (int) round($fleet * $cfg['deliveries_per_truck_day'] / 24);

        // XP from healthy operations; level up along the shared curve.
        $company->xp += (int) max(0, round($profit / 1000));
        while ($company->xp >= $company->level * Company::XP_CURVE_BASE) {
            $company->xp -= $company->level * Company::XP_CURVE_BASE;
            $company->level++;
        }

        // Reputation eases toward the strategy's target, nudged by the P&L.
        $target = $strat['rep_target'] + ($profit > 0 ? 20 : -30);
        $company->reputation = (int) max(0, min(1000,
            $company->reputation + max(-4, min(4, $target - $company->reputation > 0 ? 2 : -2))));

        // Loss streak drives the bankruptcy clock.
        $state['loss_streak'] = $profit < 0 ? (int) ($state['loss_streak'] ?? 0) + 1 : 0;

        // Expansion: a firm in the black with spare capital adds trucks.
        $expandChance = 0.15 * $strat['expand'];
        if ($profit > 0 && $company->cash > $cfg['expand_cash_floor']
            && $this->rand01($company->id * 7 + 3, $hour) < $expandChance) {
            [$lo, $hi] = $cfg['expand_batch'];
            $add = random_int($lo, $hi);
            $capex = $add * (int) $cfg['truck_capex'];
            if ($company->cash >= $capex) {
                $company->ai_fleet_size = $fleet + $add;
                $company->cash -= $capex;
            }
        }

        // Bankruptcy: sustained losses in the red end the firm; a new one is
        // founded in its place so the roster — and the rivalry — stays full.
        if ($company->cash < 0 && ($state['loss_streak'] ?? 0) >= (int) $cfg['bankrupt_after_hours']) {
            $this->bankrupt($company);

            return true;
        }

        return false;
    }

    /** Fold a rival and refound a fresh firm in place (keeps roster full). */
    protected function bankrupt(Company $company): void
    {
        $country = optional(City::find($company->headquarters_city_id))->country
            ?? config('transoria.default_country', 'IN');

        $this->news($country, 'critical', '📉',
            "{$company->name} folds",
            "{$company->name} has shut down after sustained losses, liquidating its fleet.");

        // Refound in place: a new name, fresh capital, clean books.
        $newName = $this->uniqueName();
        [$cashLo, $cashHi] = config('transoria.ai.seed_cash', [180000_00, 900000_00]);
        [$fleetLo, $fleetHi] = config('transoria.ai.seed_fleet', [3, 14]);
        [$repLo, $repHi] = config('transoria.ai.seed_reputation', [420, 760]);

        $company->update([
            'name' => $newName,
            'slug' => $this->uniqueSlug($newName),
            'ai_strategy' => $this->pickStrategy(),
            'ai_fleet_size' => random_int($fleetLo, $fleetHi),
            'cash' => random_int((int) $cashLo, (int) $cashHi),
            'reputation' => random_int($repLo, $repHi),
            'logo_color' => $this->randomColor(),
            'level' => 1,
            'xp' => 0,
            'ai_bankrupt_at' => null,
            'ai_state' => ['loss_streak' => 0, 'last_hour' => $this->currentHour(), 'founded_hour' => $this->currentHour()],
        ]);

        $this->news($country, 'info', '🚚',
            "{$newName} enters the market",
            "A new logistics firm, {$newName}, has been founded and is bidding for freight.");
    }

    /**
     * Market share of every company (human + AI) by lifetime revenue, as a
     * fraction 0..1 keyed by company id. Used by the leaderboard.
     */
    public function marketShares(Collection $companies): array
    {
        $total = (int) $companies->sum('lifetime_revenue');
        if ($total <= 0) {
            return [];
        }

        return $companies->mapWithKeys(fn (Company $c) => [
            $c->id => round($c->lifetime_revenue / $total, 4),
        ])->all();
    }

    // ---- helpers -------------------------------------------------------------

    /** Overall demand this pass from active events + the hourly market pulse. */
    protected function worldDemandFactor(): float
    {
        $pulse = $this->economy->hourlyMarketPulse(); // ~0.82..1.28
        $eventMod = 1.0;
        foreach (WorldEvent::active()->get() as $event) {
            $m = (float) ($event->modifiers['demand_modifier'] ?? 1.0);
            if ($m > 0) {
                $eventMod *= $m;
            }
        }

        return max(0.6, min(1.6, $pulse * $eventMod));
    }

    /** Deterministic 0..1 from a seed and game-hour (no RNG → reproducible). */
    protected function rand01(int $seed, int $hour): float
    {
        return (crc32($seed.':'.$hour) % 100000) / 100000;
    }

    protected function pickStrategy(): string
    {
        $strats = config('transoria.ai.strategies', []);
        $bag = [];
        foreach ($strats as $key => $s) {
            $bag = array_merge($bag, array_fill(0, (int) ($s['weight'] ?? 1), $key));
        }

        return $bag ? $bag[array_rand($bag)] : 'regional';
    }

    protected function strategy(?string $key): array
    {
        $all = config('transoria.ai.strategies', []);

        return $all[$key] ?? ($all['regional'] ?? ['revenue' => 1, 'cost' => 1, 'expand' => 1, 'rep_target' => 600]);
    }

    protected function uniqueName(): string
    {
        $prefixes = config('transoria.ai.name_prefixes', ['Apex']);
        $suffixes = config('transoria.ai.name_suffixes', ['Logistics']);

        for ($attempt = 0; $attempt < 40; $attempt++) {
            $name = $prefixes[array_rand($prefixes)].' '.$suffixes[array_rand($suffixes)];
            if (! Company::where('name', $name)->exists()) {
                return $name;
            }
        }

        return $prefixes[array_rand($prefixes)].' '.$suffixes[array_rand($suffixes)].' '.random_int(2, 99);
    }

    protected function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $n = 1;
        while (Company::where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$n);
        }

        return $slug;
    }

    protected function randomColor(): string
    {
        $palette = ['#38bdf8', '#f472b6', '#a78bfa', '#34d399', '#fbbf24', '#fb7185',
            '#60a5fa', '#f97316', '#2dd4bf', '#c084fc', '#4ade80', '#e879f9'];

        return $palette[array_rand($palette)];
    }

    protected function news(string $country, string $severity, string $icon, string $headline, string $body): void
    {
        try {
            NewsItem::create([
                'country' => $country,
                'category' => 'business',
                'severity' => $severity,
                'icon' => $icon,
                'headline' => $headline,
                'body' => $body,
                'occurred_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // News is flavour — never let it break the world step.
        }
    }
}
