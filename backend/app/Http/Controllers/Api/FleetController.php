<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesCompany;
use App\Http\Controllers\Controller;
use App\Http\Resources\VehicleModelResource;
use App\Http\Resources\VehicleResource;
use App\Models\Vehicle;
use App\Models\VehicleModel;
use App\Services\CompanyService;
use App\Services\GarageService;
use Illuminate\Http\Request;
use RuntimeException;

class FleetController extends Controller
{
    use ResolvesCompany;

    public function __construct(
        private readonly CompanyService $companies,
        private readonly GarageService $garage,
    ) {}

    /** Dry-run cost of "Service All" and "Fuel All" over the idle fleet. */
    public function serviceEstimate(Request $request)
    {
        $company = $this->company($request);

        return response()->json($this->garage->estimateUpkeepAll($company));
    }

    /** One click: service (repair + tyres + oil + battery) every idle vehicle. */
    public function serviceAll(Request $request)
    {
        $company = $this->company($request);
        $result = $this->garage->serviceAll($company);

        if ($result['serviced'] === 0) {
            return response()->json(['message' => 'No idle vehicle needed servicing.']);
        }

        return response()->json([
            'message' => "Serviced {$result['serviced']} vehicle(s).",
            'serviced' => $result['serviced'],
        ]);
    }

    /** One click: refuel every idle vehicle to a full tank. */
    public function refuelAll(Request $request)
    {
        $company = $this->company($request);
        $result = $this->garage->refuelAll($company);

        if ($result['fuelled'] === 0) {
            return response()->json(['message' => 'No idle vehicle needed fuel.']);
        }

        return response()->json([
            'message' => "Refuelled {$result['fuelled']} vehicle(s).",
            'fuelled' => $result['fuelled'],
        ]);
    }

    /** The company's owned vehicles. */
    public function index(Request $request)
    {
        $company = $this->company($request);

        $vehicles = Vehicle::where('company_id', $company->id)
            ->with(['model', 'city'])
            ->orderBy('status')
            ->get();

        // Per-km upkeep rates so the client can estimate a run's true service &
        // fuel cost (auto-charged on arrival). Costs are integer cents.
        $g = config('transoria.garage');
        $s = config('transoria.shipment');

        return VehicleResource::collection($vehicles)->additional([
            'upkeep' => [
                'repair_cost_per_point' => (int) $g['repair_cost_per_point'],
                'tire_cost_per_point' => (int) $g['tire_cost_per_point'],
                'oil_change_cost' => (int) $g['oil_change_cost'],
                'battery_cost' => (int) $g['battery_cost'],
                'condition_loss_per_1000km' => (float) $s['condition_loss_per_1000km'],
                'tire_loss_per_1000km' => (float) $s['tire_loss_per_1000km'],
                'oil_loss_per_1000km' => (float) $g['oil_loss_per_1000km'],
                'battery_loss_per_1000km' => (float) $g['battery_loss_per_1000km'],
            ],
        ]);
    }

    /** The dealership catalog with lock/afford flags for this company. */
    public function dealership(Request $request)
    {
        return VehicleModelResource::collection(
            VehicleModel::orderBy('unlock_level')->orderBy('price')->get()
        );
    }

    /** Buy a vehicle from the dealership. */
    public function buy(Request $request, VehicleModel $model)
    {
        $company = $this->company($request);

        try {
            $vehicle = $this->companies->buyVehicle($company, $model);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return (new VehicleResource($vehicle->load('model', 'city')))
            ->response()->setStatusCode(201);
    }

    /** Refuel a vehicle at the local pump, charging cash at the fuel price. */
    public function refuel(Request $request, Vehicle $vehicle)
    {
        $company = $this->company($request);

        $data = $request->validate([
            'liters' => ['nullable', 'numeric', 'min:1'],
        ]);

        try {
            $vehicle = $this->companies->refuelVehicle($company, $vehicle, $data['liters'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return new VehicleResource($vehicle->load('model', 'city'));
    }
}
