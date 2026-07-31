<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesCompany;
use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Commodity;
use App\Models\Warehouse;
use App\Services\EconomyService;
use App\Services\WarehouseService;
use Illuminate\Http\Request;
use RuntimeException;

class WarehouseController extends Controller
{
    use ResolvesCompany;

    public function __construct(
        private readonly WarehouseService $warehouses,
        private readonly EconomyService $economy,
    ) {}

    public function index(Request $request)
    {
        $company = $this->company($request);
        $warehouses = Warehouse::where('company_id', $company->id)
            ->with(['city', 'inventory.commodity'])->get();

        $data = $warehouses->map(function (Warehouse $w) {
            return [
                'id' => $w->id,
                'name' => $w->name,
                'tier' => $w->tier,
                'capacity' => $w->capacity,
                'used' => $w->usedCapacity(),
                'upkeep' => (int) $w->upkeep,
                'city' => ['id' => $w->city?->id, 'name' => $w->city?->name],
                'inventory' => $w->inventory->map(function ($row) use ($w) {
                    $local = $this->economy->latestPrice($w->city_id, $row->commodity);

                    return [
                        'commodity_id' => $row->commodity_id,
                        'commodity' => $row->commodity->name,
                        'units' => $row->units,
                        'avg_unit_cost' => (float) $row->avg_unit_cost,
                        'local_price' => round($local, 2),
                        'unrealized' => round(($local - $row->avg_unit_cost) * $row->units, 2),
                    ];
                })->values(),
            ];
        });

        return response()->json([
            'data' => $data,
            'build_cost' => (int) config('transoria.warehouse.build_cost'),
        ]);
    }

    public function build(Request $request)
    {
        $company = $this->company($request);
        $data = $request->validate([
            'city_id' => ['required', 'integer', 'exists:cities,id'],
            'name' => ['nullable', 'string', 'max:60'],
        ]);

        try {
            $w = $this->warehouses->build($company, City::findOrFail($data['city_id']), $data['name'] ?? '');
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Warehouse built.', 'id' => $w->id], 201);
    }

    public function buy(Request $request, Warehouse $warehouse)
    {
        return $this->trade($request, $warehouse, 'buy');
    }

    public function sell(Request $request, Warehouse $warehouse)
    {
        return $this->trade($request, $warehouse, 'sell');
    }

    private function trade(Request $request, Warehouse $warehouse, string $action)
    {
        $company = $this->company($request);
        $data = $request->validate([
            'commodity_id' => ['required', 'integer', 'exists:commodities,id'],
            'units' => ['required', 'integer', 'min:1', 'max:100000'],
        ]);
        $commodity = Commodity::findOrFail($data['commodity_id']);

        try {
            $this->warehouses->$action($company, $warehouse, $commodity, $data['units']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => ucfirst($action).' complete.']);
    }
}
