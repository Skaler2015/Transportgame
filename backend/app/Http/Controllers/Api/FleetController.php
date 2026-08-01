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

    /** What "Service & Fuel All" would cost right now (dry run, no charge). */
    public function serviceEstimate(Request $request)
    {
        $company = $this->company($request);

        return response()->json($this->garage->estimateFullServiceAll($company));
    }

    /** One click: full-service + refuel every idle vehicle. */
    public function fullServiceAll(Request $request)
    {
        $company = $this->company($request);
        $result = $this->garage->fullServiceAll($company);

        if ($result['serviced'] === 0) {
            return response()->json(['message' => 'No idle vehicle needed servicing.']);
        }

        return response()->json([
            'message' => "Serviced & fuelled {$result['serviced']} vehicle(s).",
            'serviced' => $result['serviced'],
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

        return VehicleResource::collection($vehicles);
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
