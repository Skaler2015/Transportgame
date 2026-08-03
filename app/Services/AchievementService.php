<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyAchievement;
use App\Models\Driver;
use App\Models\LedgerEntry;
use App\Models\Trailer;
use App\Models\Vehicle;
use App\Models\Warehouse;

/**
 * Config-driven achievements. Definitions live in config('transoria.achievements');
 * this service evaluates a company's live metrics, unlocks any newly-met ones,
 * grants their rewards through the ledger, and reports what was just unlocked so
 * the UI can celebrate it. Fully additive — add achievements in config alone.
 */
class AchievementService
{
    public function __construct(private readonly LedgerService $ledger) {}

    /** @return array<int,array<string,mixed>> */
    public function definitions(): array
    {
        return config('transoria.achievements', []);
    }

    /** Current values for every metric an achievement can test. */
    public function valuesFor(Company $company): array
    {
        return [
            'deliveries' => (int) $company->shipments_completed,
            'revenue' => (int) $company->lifetime_revenue,
            'cash' => (int) $company->cash,
            'level' => (int) $company->level,
            'reputation' => (int) $company->reputation,
            'fleet' => Vehicle::where('company_id', $company->id)->count(),
            'drivers' => Driver::where('company_id', $company->id)->count(),
            'trailers' => Trailer::where('company_id', $company->id)->count(),
            'warehouses' => Warehouse::where('company_id', $company->id)->count(),
        ];
    }

    /**
     * Unlock any newly-earned achievements and grant rewards.
     *
     * @return array<int,array<string,mixed>> the definitions just unlocked
     */
    public function check(Company $company): array
    {
        $unlocked = CompanyAchievement::where('company_id', $company->id)
            ->pluck('achievement_key')->flip();

        $values = $this->valuesFor($company);
        $new = [];

        foreach ($this->definitions() as $def) {
            if ($unlocked->has($def['key'])) {
                continue;
            }
            $value = $values[$def['metric']] ?? 0;
            if ($value < $def['threshold']) {
                continue;
            }

            CompanyAchievement::create([
                'company_id' => $company->id,
                'achievement_key' => $def['key'],
                'unlocked_at' => now(),
            ]);

            if (! empty($def['xp'])) {
                $company->xp += (int) $def['xp'];
            }
            if (! empty($def['cash'])) {
                $this->ledger->post($company, LedgerEntry::CAT_REVENUE,
                    "Achievement unlocked: {$def['name']}", (int) $def['cash']);
            }

            $new[] = $def;
        }

        if ($new) {
            $company->save();
        }

        return $new;
    }
}
