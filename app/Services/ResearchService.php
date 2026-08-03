<?php

namespace App\Services;

use App\Models\Company;

/**
 * Resolves a company's unlocked research nodes into a flat map of summed
 * operational bonuses used by the shipment engine and payouts.
 */
class ResearchService
{
    /** @return array<string,float> summed bonus map (bonus_key => total) */
    public function bonuses(Company $company): array
    {
        $tree = config('transoria.research');
        $unlocked = $company->research()->pluck('node_key')->all();

        $totals = [];
        foreach ($unlocked as $key) {
            foreach (($tree[$key]['bonus'] ?? []) as $bonusKey => $value) {
                $totals[$bonusKey] = ($totals[$bonusKey] ?? 0) + $value;
            }
        }

        return $totals;
    }

    /** Whether a node's prerequisites are all satisfied for a company. */
    public function canUnlock(Company $company, string $nodeKey): bool
    {
        $tree = config('transoria.research');
        if (! isset($tree[$nodeKey])) {
            return false;
        }
        if ($company->research()->where('node_key', $nodeKey)->exists()) {
            return false;
        }

        $unlocked = $company->research()->pluck('node_key')->all();
        foreach ($tree[$nodeKey]['requires'] as $req) {
            if (! in_array($req, $unlocked, true)) {
                return false;
            }
        }

        return true;
    }
}
