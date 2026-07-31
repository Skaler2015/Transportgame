<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Commodity;
use App\Models\MarketPrice;
use Illuminate\Http\Request;

class MarketController extends Controller
{
    /** Latest local price + demand for a commodity across every city. */
    public function index(Request $request)
    {
        $data = $request->validate([
            'commodity_id' => ['required', 'integer', 'exists:commodities,id'],
        ]);

        $commodity = Commodity::findOrFail($data['commodity_id']);

        // Latest snapshot per city for this commodity.
        $latest = MarketPrice::query()
            ->where('commodity_id', $commodity->id)
            ->orderByDesc('recorded_at')
            ->get()
            ->unique('city_id')
            ->values();

        $cities = \App\Models\City::whereIn('id', $latest->pluck('city_id'))->get()->keyBy('id');

        $rows = $latest->map(function (MarketPrice $mp) use ($cities, $commodity) {
            $city = $cities[$mp->city_id] ?? null;

            return [
                'city_id' => $mp->city_id,
                'city' => $city?->name,
                'region' => $city?->region,
                'price' => (float) $mp->price,
                'base_price' => (float) $commodity->base_price,
                'demand_index' => (float) $mp->demand_index,
                'delta_pct' => round(($mp->price - $commodity->base_price) / $commodity->base_price * 100, 1),
            ];
        })->sortByDesc('price')->values();

        return response()->json([
            'commodity' => [
                'id' => $commodity->id,
                'name' => $commodity->name,
                'base_price' => (float) $commodity->base_price,
            ],
            'markets' => $rows,
        ]);
    }

    /** Price history series for one city/commodity (for charts). */
    public function history(Request $request)
    {
        $data = $request->validate([
            'city_id' => ['required', 'integer', 'exists:cities,id'],
            'commodity_id' => ['required', 'integer', 'exists:commodities,id'],
        ]);

        $series = MarketPrice::query()
            ->where('city_id', $data['city_id'])
            ->where('commodity_id', $data['commodity_id'])
            ->orderBy('recorded_at')
            ->limit(200)
            ->get(['price', 'demand_index', 'recorded_at'])
            ->map(fn (MarketPrice $mp) => [
                'price' => (float) $mp->price,
                'demand_index' => (float) $mp->demand_index,
                'at' => $mp->recorded_at->toIso8601String(),
            ]);

        return response()->json(['data' => $series]);
    }
}
