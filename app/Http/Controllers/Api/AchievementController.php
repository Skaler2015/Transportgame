<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesCompany;
use App\Http\Controllers\Controller;
use App\Models\CompanyAchievement;
use App\Services\AchievementService;
use Illuminate\Http\Request;

class AchievementController extends Controller
{
    use ResolvesCompany;

    public function __construct(private readonly AchievementService $achievements) {}

    /** Full achievement catalogue with unlock state + live progress. */
    public function index(Request $request)
    {
        $company = $this->company($request);

        // Catch anything newly earned since the last visit.
        $this->achievements->check($company);

        $unlocked = CompanyAchievement::where('company_id', $company->id)
            ->get()->keyBy('achievement_key');
        $values = $this->achievements->valuesFor($company);

        $items = collect($this->achievements->definitions())->map(function ($def) use ($unlocked, $values) {
            $value = $values[$def['metric']] ?? 0;
            $row = $unlocked->get($def['key']);

            return [
                'key' => $def['key'],
                'name' => $def['name'],
                'description' => $def['desc'],
                'icon' => $def['icon'],
                'category' => $def['category'],
                'metric' => $def['metric'],
                'threshold' => (int) $def['threshold'],
                'progress' => min((int) $value, (int) $def['threshold']),
                'progress_pct' => $def['threshold'] > 0
                    ? min(100, (int) round($value / $def['threshold'] * 100)) : 100,
                'reward_cash' => (int) ($def['cash'] ?? 0),
                'reward_xp' => (int) ($def['xp'] ?? 0),
                'unlocked' => (bool) $row,
                'unlocked_at' => $row?->unlocked_at?->toIso8601String(),
            ];
        })->values();

        return response()->json([
            'data' => $items,
            'summary' => [
                'unlocked' => $unlocked->count(),
                'total' => count($this->achievements->definitions()),
            ],
        ]);
    }
}
