<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesCompany;
use App\Http\Controllers\Controller;
use App\Models\Mission;
use App\Services\MissionService;
use Illuminate\Http\Request;
use RuntimeException;

class MissionController extends Controller
{
    use ResolvesCompany;

    public function __construct(private readonly MissionService $missions) {}

    public function index(Request $request)
    {
        $company = $this->company($request);
        $this->missions->ensure($company);

        $missions = Mission::where('company_id', $company->id)
            ->whereIn('status', [Mission::STATUS_ACTIVE, Mission::STATUS_COMPLETED])
            ->orderBy('period')->orderByDesc('status')->get()
            ->map(fn (Mission $m) => [
                'id' => $m->id,
                'period' => $m->period,
                'metric' => $m->metric,
                'title' => $m->title,
                'description' => $m->description,
                'target' => $m->target,
                'progress' => $m->progress,
                'percent' => $m->percent(),
                'reward_cash' => (int) $m->reward_cash,
                'reward_xp' => $m->reward_xp,
                'status' => $m->status,
                'expires_at' => $m->expires_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $missions]);
    }

    public function claim(Request $request, Mission $mission)
    {
        $company = $this->company($request);

        try {
            $this->missions->claim($company, $mission);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Reward claimed!']);
    }
}
