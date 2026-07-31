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
    public function cities()
    {
        return CityResource::collection(City::orderBy('unlock_level')->orderBy('name')->get());
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
