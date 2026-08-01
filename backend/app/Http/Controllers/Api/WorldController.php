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

    /** Top companies by estimated value for the leaderboard. */
    public function leaderboard(Request $request)
    {
        $companies = Company::query()
            ->withCount('vehicles')
            ->orderByDesc('reputation')
            ->orderByDesc('cash')
            ->limit(50)
            ->get()
            ->map(fn (Company $c, int $i) => [
                'rank' => $i + 1,
                'name' => $c->name,
                'logo_color' => $c->logo_color,
                'level' => $c->level,
                'reputation' => $c->reputation,
                'value' => $c->estimatedValue(),
                'shipments_completed' => $c->shipments_completed,
                'fleet_size' => $c->vehicles_count,
            ]);

        return response()->json(['data' => $companies]);
    }
}
