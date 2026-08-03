<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesCompany;
use App\Http\Controllers\Controller;
use App\Models\CompanyResearch;
use App\Services\ResearchService;
use Illuminate\Http\Request;

class ResearchController extends Controller
{
    use ResolvesCompany;

    public function __construct(private readonly ResearchService $research) {}

    /** The full research tree annotated with this company's progress. */
    public function index(Request $request)
    {
        $company = $this->company($request);
        $tree = config('transoria.research');
        $unlocked = $company->research()->pluck('node_key')->all();

        $nodes = collect($tree)->map(function ($def, $key) use ($company, $unlocked) {
            return [
                'key' => $key,
                'name' => $def['name'],
                'branch' => $def['branch'],
                'cost' => $def['cost'],
                'requires' => $def['requires'],
                'bonus' => $def['bonus'],
                'unlocked' => in_array($key, $unlocked, true),
                'available' => $this->research->canUnlock($company, $key),
                'affordable' => $company->research_points >= $def['cost'],
            ];
        })->values();

        return response()->json([
            'research_points' => $company->research_points,
            'nodes' => $nodes,
            'active_bonuses' => $this->research->bonuses($company),
        ]);
    }

    /** Spend research points to unlock a node. */
    public function unlock(Request $request, string $node)
    {
        $company = $this->company($request);
        $tree = config('transoria.research');

        if (! isset($tree[$node])) {
            return response()->json(['message' => 'Unknown research node.'], 404);
        }
        if (! $this->research->canUnlock($company, $node)) {
            return response()->json(['message' => 'Prerequisites not met or already unlocked.'], 422);
        }
        if ($company->research_points < $tree[$node]['cost']) {
            return response()->json(['message' => 'Not enough research points.'], 422);
        }

        $company->decrement('research_points', $tree[$node]['cost']);
        CompanyResearch::create([
            'company_id' => $company->id,
            'node_key' => $node,
            'unlocked_at' => now(),
        ]);

        return response()->json([
            'message' => "Unlocked {$tree[$node]['name']}.",
            'research_points' => $company->fresh()->research_points,
            'active_bonuses' => $this->research->bonuses($company),
        ]);
    }
}
