<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CityResource;
use App\Http\Resources\CommodityResource;
use App\Http\Resources\WorldEventResource;
use App\Models\City;
use App\Models\Commodity;
use App\Models\Company;
use App\Models\WorldEvent;
use App\Services\AiCompanyService;
use Illuminate\Http\Request;

class WorldController extends Controller
{
    /** Cities for a country (defaults to the caller's company / the default). */
    public function cities(Request $request)
    {
        $country = $request->query('country');
        if (! array_key_exists($country, config('transoria.countries'))) {
            $country = $request->user()?->company?->country ?? config('transoria.default_country');
        }

        return CityResource::collection(
            City::where('country', $country)->orderBy('unlock_level')->orderBy('name')->get()
        );
    }

    /** The list of playable countries (for the sign-up picker). */
    public function countries()
    {
        return response()->json(['data' => collect(config('transoria.countries'))
            ->map(fn ($name, $code) => ['code' => $code, 'name' => $name])->values()]);
    }

    public function commodities()
    {
        return CommodityResource::collection(Commodity::orderBy('unlock_level')->orderBy('name')->get());
    }

    public function events()
    {
        return WorldEventResource::collection(WorldEvent::active()->orderByDesc('starts_at')->get());
    }

    /**
     * Leaderboard of every firm — human and AI — ranked by reputation then cash.
     * Doubles as the lazy tick for rival companies: viewing the world nudges the
     * AI economy forward (rate-limited inside stepDue).
     */
    public function leaderboard(Request $request, AiCompanyService $ai)
    {
        $ai->stepDue($request->user()?->company?->country);

        // Whole-world revenue drives market-share fractions.
        $worldRevenue = (int) Company::sum('lifetime_revenue');

        $companies = Company::query()
            ->withCount('vehicles')
            ->orderByDesc('reputation')
            ->orderByDesc('cash')
            ->limit(50)
            ->get()
            ->values()
            ->map(fn (Company $c, int $i) => [
                'rank' => $i + 1,
                'name' => $c->name,
                'logo_color' => $c->logo_color,
                'level' => $c->level,
                'reputation' => $c->reputation,
                'value' => $c->estimatedValue(),
                'shipments_completed' => $c->shipments_completed,
                'fleet_size' => $c->isAi() ? (int) $c->ai_fleet_size : (int) $c->vehicles_count,
                'is_ai' => $c->isAi(),
                'strategy' => $c->isAi() ? $c->ai_strategy : null,
                'market_share' => $worldRevenue > 0 ? round($c->lifetime_revenue / $worldRevenue, 4) : 0,
            ]);

        return response()->json([
            'data' => $companies,
            'meta' => [
                'total_firms' => (int) Company::count(),
                'ai_firms' => (int) Company::ai()->whereNull('ai_bankrupt_at')->count(),
                'world_revenue' => $worldRevenue,
            ],
        ]);
    }
}
