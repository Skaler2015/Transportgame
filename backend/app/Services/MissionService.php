<?php

namespace App\Services;

use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\Mission;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Time-boxed objectives that reward Credits + XP. Missions are topped up per
 * company and progressed by gameplay events (delivery completed, revenue, …).
 */
class MissionService
{
    public function __construct(private readonly LedgerService $ledger) {}

    /** Ensure a company has its full slate of active daily/weekly missions. */
    public function ensure(Company $company): void
    {
        // Retire anything past its deadline.
        Mission::where('company_id', $company->id)
            ->where('status', Mission::STATUS_ACTIVE)
            ->where('expires_at', '<=', now())
            ->update(['status' => Mission::STATUS_EXPIRED]);

        foreach (['daily' => 'daily_count', 'weekly' => 'weekly_count'] as $period => $countKey) {
            $active = Mission::where('company_id', $company->id)
                ->where('period', $period)
                ->whereIn('status', [Mission::STATUS_ACTIVE, Mission::STATUS_COMPLETED])
                ->count();

            $need = (int) config("transoria.missions.$countKey") - $active;
            if ($need <= 0) {
                continue;
            }

            $templates = collect(config('transoria.missions.templates'))
                ->where('period', $period)->values();
            if ($templates->isEmpty()) {
                continue;
            }

            for ($i = 0; $i < $need; $i++) {
                $t = $templates[array_rand($templates->all())];
                $scale = 1 + ($company->level - 1) * 0.05;

                Mission::create([
                    'company_id' => $company->id,
                    'period' => $period,
                    'metric' => $t['metric'],
                    'title' => $t['title'],
                    'description' => $this->describe($t),
                    'target' => $t['target'],
                    'progress' => 0,
                    'reward_cash' => (int) round($t['cash'] * $scale),
                    'reward_xp' => (int) round($t['xp'] * $scale),
                    'status' => Mission::STATUS_ACTIVE,
                    'expires_at' => $period === 'daily' ? now()->addDay() : now()->addWeek(),
                ]);
            }
        }

        $company->missions_generated_at = now();
        $company->save();
    }

    /** Advance all active missions tracking $metric by $amount for a company. */
    public function progress(Company $company, string $metric, int $amount = 1): void
    {
        if ($amount <= 0) {
            return;
        }

        Mission::where('company_id', $company->id)
            ->where('status', Mission::STATUS_ACTIVE)
            ->where('metric', $metric)
            ->get()
            ->each(function (Mission $m) use ($amount) {
                $m->progress = min($m->target, $m->progress + $amount);
                if ($m->progress >= $m->target) {
                    $m->status = Mission::STATUS_COMPLETED;
                }
                $m->save();
            });
    }

    /** Claim a completed mission's reward. */
    public function claim(Company $company, Mission $mission): Mission
    {
        if ($mission->company_id !== $company->id) {
            throw new RuntimeException('That mission is not yours.');
        }
        if ($mission->status !== Mission::STATUS_COMPLETED) {
            throw new RuntimeException('That mission is not complete yet.');
        }

        DB::transaction(function () use ($company, $mission) {
            if ($mission->reward_cash > 0) {
                $this->ledger->post($company, LedgerEntry::CAT_REVENUE,
                    "Mission reward: {$mission->title}", $mission->reward_cash, $mission);
            }
            $company->xp += $mission->reward_xp;
            $company->save();

            $mission->status = Mission::STATUS_CLAIMED;
            $mission->save();
        });

        return $mission;
    }

    private function describe(array $t): string
    {
        return match ($t['metric']) {
            'deliveries' => "Complete {$t['target']} deliveries.",
            'on_time' => "Deliver {$t['target']} shipments on time.",
            'revenue' => "Earn ₡".number_format($t['target'])." in delivery revenue.",
            'distance' => "Cover {$t['target']} km of deliveries.",
            default => 'Complete the objective.',
        };
    }
}
