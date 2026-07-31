<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesCompany;
use App\Http\Controllers\Controller;
use App\Http\Resources\VehicleModelResource;
use App\Http\Resources\VehicleResource;
use App\Models\Vehicle;
use App\Models\VehicleModel;
use App\Services\CompanyService;
use Illuminate\Http\Request;
use RuntimeException;

class FleetController extends Controller
{
    use ResolvesCompany;

    public function __construct(private readonly CompanyService $companies) {}

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
}
