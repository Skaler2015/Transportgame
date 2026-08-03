<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Company;
use App\Models\Contract;
use App\Models\NewsItem;
use App\Models\Shipment;
use App\Models\User;
use App\Models\WorldEvent;
use App\Services\EventService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Operator control room. Every route here is behind auth:sanctum + the `admin`
 * middleware, so only flagged accounts reach it. Actions are deliberately
 * coarse and world-level (economy, events, moderation) — never per-player
 * cheats — and are logged as news where players would notice the effect.
 */
class AdminController extends Controller
{
    /** Top-line world health metrics. */
    public function dashboard()
    {
        return response()->json([
            'players' => (int) User::count(),
            'banned' => (int) User::whereNotNull('banned_at')->count(),
            'companies' => (int) Company::human()->count(),
            'ai_companies' => (int) Company::ai()->whereNull('ai_bankrupt_at')->count(),
            'vehicles' => (int) DB::table('vehicles')->count(),
            'active_shipments' => (int) Shipment::where('status', Shipment::STATUS_EN_ROUTE)->count(),
            'open_contracts' => (int) Contract::where('status', Contract::STATUS_OPEN)->count(),
            'active_events' => (int) WorldEvent::active()->count(),
            'player_cash' => (int) Company::human()->sum('cash'),
            'world_revenue' => (int) Company::sum('lifetime_revenue'),
            'deliveries' => (int) Company::sum('shipments_completed'),
            'regions' => City::query()->distinct()->count('region'),
        ]);
    }

    /** Paginated player list with company + moderation state. */
    public function users(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        $users = User::query()
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w
                ->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%")))
            ->with('company:id,user_id,name,cash,level,reputation,is_ai')
            ->orderByDesc('id')
            ->paginate(25);

        return response()->json([
            'data' => collect($users->items())->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'is_admin' => $u->isAdmin(),
                'banned_at' => $u->banned_at?->toIso8601String(),
                'created_at' => $u->created_at?->toDateString(),
                'company' => $u->company ? [
                    'name' => $u->company->name,
                    'cash' => (int) $u->company->cash,
                    'level' => (int) $u->company->level,
                    'reputation' => (int) $u->company->reputation,
                ] : null,
            ]),
            'meta' => ['total' => $users->total(), 'per_page' => $users->perPage(), 'current_page' => $users->currentPage(), 'last_page' => $users->lastPage()],
        ]);
    }

    public function banUser(Request $request, User $user)
    {
        if ($user->id === $request->user()->id) {
            abort(422, 'You cannot ban yourself.');
        }
        $ban = $user->banned_at === null;
        // forceFill: is_admin/banned_at are intentionally NOT mass-assignable.
        $user->forceFill(['banned_at' => $ban ? now() : null])->save();

        return response()->json(['banned' => $ban]);
    }

    public function toggleAdmin(Request $request, User $user)
    {
        if ($user->id === $request->user()->id) {
            abort(422, 'You cannot change your own admin role.');
        }
        $user->forceFill(['is_admin' => ! $user->is_admin])->save();

        return response()->json(['is_admin' => (bool) $user->is_admin]);
    }

    /** Active world events + the spawnable catalog. */
    public function events()
    {
        $catalog = collect(config('transoria.events'))
            ->map(fn ($def, $type) => ['type' => $type, 'title' => $def['title'], 'severity' => $def['severity']])
            ->values();

        return response()->json([
            'active' => WorldEvent::active()->orderByDesc('starts_at')->get()
                ->map(fn (WorldEvent $e) => [
                    'id' => $e->id, 'type' => $e->type, 'title' => $e->title, 'severity' => $e->severity,
                    'region' => $e->region, 'ends_at' => $e->ends_at?->toIso8601String(),
                ]),
            'catalog' => $catalog,
            'regions' => City::query()->distinct()->orderBy('region')->pluck('region'),
        ]);
    }

    public function spawnEvent(Request $request, EventService $events)
    {
        $data = $request->validate([
            'type' => ['required', 'string'],
            'region' => ['nullable', 'string'],
            'hours' => ['nullable', 'integer', 'min:1', 'max:72'],
        ]);

        $event = $events->spawn($data['type'], ($data['region'] ?? null) ?: null, $data['hours'] ?? null);
        abort_if(! $event, 422, 'Unknown event type.');

        NewsItem::create([
            'country' => null, 'category' => 'event', 'severity' => $event->severity,
            'icon' => '📢', 'headline' => $event->title, 'body' => $event->description, 'occurred_at' => now(),
        ]);

        return response()->json(['id' => $event->id], 201);
    }

    public function endEvent(WorldEvent $event)
    {
        $event->update(['ends_at' => now()]);

        return response()->json(['ended' => true]);
    }

    /** Nudge fuel prices across a region (or everywhere). */
    public function setFuel(Request $request)
    {
        $data = $request->validate([
            'region' => ['nullable', 'string'],
            'delta_pct' => ['required', 'numeric', 'min:-50', 'max:100'],
        ]);

        $factor = 1 + $data['delta_pct'] / 100;
        $affected = 0;
        City::query()
            ->when(! empty($data['region']), fn ($b) => $b->where('region', $data['region']))
            ->chunkById(200, function ($cities) use ($factor, &$affected) {
                foreach ($cities as $city) {
                    $city->update(['fuel_price' => round(max(0.6, min(3.0, $city->fuel_price * $factor)), 2)]);
                    $affected++;
                }
            });

        return response()->json(['affected' => $affected]);
    }

    /** Advance the whole world one tick on demand. */
    public function tick()
    {
        Artisan::call('world:tick', ['--quiet-summary' => true]);

        return response()->json(['ticked' => true]);
    }

    /** Live monitoring: recent shipments, latest news, active events. */
    public function live()
    {
        $shipments = Shipment::with(['company:id,name', 'contract.origin:id,name', 'contract.destination:id,name'])
            ->where('status', Shipment::STATUS_EN_ROUTE)
            ->orderByDesc('departed_at')->limit(20)->get()
            ->map(fn (Shipment $s) => [
                'id' => $s->id,
                'company' => $s->company?->name,
                'lane' => ($s->contract?->origin?->name ?? '?').' → '.($s->contract?->destination?->name ?? '?'),
                'eta_at' => $s->eta_at?->toIso8601String(),
            ]);

        return response()->json([
            'shipments' => $shipments,
            'news' => NewsItem::orderByDesc('occurred_at')->limit(15)->get(['icon', 'headline', 'severity', 'occurred_at']),
            'events' => WorldEvent::active()->count(),
        ]);
    }
}
