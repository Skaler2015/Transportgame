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
                'capacity' => $w->effectiveCapacity(),
                'base_capacity' => $w->capacity,
                'used' => $w->usedCapacity(),
                'upkeep' => (int) $w->upkeep,
                'cold_storage' => (bool) $w->cold_storage,
                'hazmat_certified' => (bool) $w->hazmat_certified,
                'automated' => (bool) $w->automated,
                'staff_level' => (int) $w->staff_level,
                'security_level' => (int) $w->security_level,
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

        $cfg = config('transoria.warehouse');

        return response()->json([
            'data' => $data,
            'build_cost' => (int) $cfg['build_cost'],
            'upgrade_costs' => [
                'expand' => (int) $cfg['expand_cost'],
                'cold' => (int) $cfg['cold_cost'],
                'hazmat' => (int) $cfg['hazmat_cost'],
                'automation' => (int) $cfg['automation_cost'],
                'staff' => (int) $cfg['staff_cost'],
                'security' => (int) $cfg['security_cost'],
            ],
            'max' => [
                'tier' => (int) $cfg['max_tier'],
                'staff' => (int) $cfg['max_staff_level'],
                'security' => (int) $cfg['max_security_level'],
            ],
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

    /** Upgrade a warehouse: expand | cold | hazmat | automation | staff | security. */
    public function upgrade(Request $request, Warehouse $warehouse)
    {
        $company = $this->company($request);
        $data = $request->validate([
            'type' => ['required', 'in:expand,cold,hazmat,automation,staff,security'],
        ]);

        try {
            $result = $this->warehouses->upgrade($company, $warehouse, $data['type']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => $result['message']]);
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
