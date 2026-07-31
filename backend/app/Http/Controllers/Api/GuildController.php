<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesCompany;
use App\Http\Controllers\Controller;
use App\Models\Guild;
use App\Services\GuildService;
use Illuminate\Http\Request;
use RuntimeException;

class GuildController extends Controller
{
    use ResolvesCompany;

    public function __construct(private readonly GuildService $guilds) {}

    /** Directory of guilds + the player's current guild. */
    public function index(Request $request)
    {
        $company = $this->company($request);

        $guilds = Guild::withCount('members')
            ->orderByDesc('total_reputation')->limit(50)->get()
            ->map(fn (Guild $g) => $this->present($g));

        $mine = null;
        if ($company->guild_id) {
            $g = $company->guild()->with(['members' => fn ($q) => $q->orderByDesc('reputation')])->first();
            $mine = array_merge($this->present($g), [
                'role' => $company->guild_role,
                'members' => $g->members->map(fn ($m) => [
                    'name' => $m->name,
                    'logo_color' => $m->logo_color,
                    'level' => $m->level,
                    'reputation' => $m->reputation,
                    'contribution' => (int) $m->guild_contribution,
                    'is_owner' => $m->id === $g->owner_company_id,
                ])->values(),
            ]);
        }

        return response()->json([
            'mine' => $mine,
            'directory' => $guilds,
            'create_cost' => (int) config('transoria.guild.create_cost'),
        ]);
    }

    public function create(Request $request)
    {
        $company = $this->company($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:40'],
            'tag' => ['required', 'string', 'max:6'],
        ]);

        try {
            $g = $this->guilds->create($company, $data['name'], $data['tag']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => "Guild {$g->name} founded!", 'id' => $g->id], 201);
    }

    public function join(Request $request, Guild $guild)
    {
        $company = $this->company($request);
        try {
            $this->guilds->join($company, $guild);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => "Joined {$guild->name}."]);
    }

    public function leave(Request $request)
    {
        $company = $this->company($request);
        try {
            $this->guilds->leave($company);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'You left the guild.']);
    }

    public function contribute(Request $request)
    {
        $company = $this->company($request);
        $data = $request->validate(['amount' => ['required', 'integer', 'min:1']]);

        try {
            $this->guilds->contribute($company, $data['amount']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Contribution added to the treasury.']);
    }

    private function present(Guild $g): array
    {
        return [
            'id' => $g->id,
            'name' => $g->name,
            'tag' => $g->tag,
            'emblem_color' => $g->emblem_color,
            'description' => $g->description,
            'treasury' => (int) $g->treasury,
            'member_count' => $g->member_count,
            'total_reputation' => (int) $g->total_reputation,
        ];
    }
}
