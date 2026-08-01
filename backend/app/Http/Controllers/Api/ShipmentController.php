<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesCompany;
use App\Http\Controllers\Controller;
use App\Http\Resources\ShipmentResource;
use App\Models\Contract;
use App\Models\Driver;
use App\Models\Shipment;
use App\Models\Vehicle;
use App\Services\ShipmentService;
use Illuminate\Http\Request;
use RuntimeException;

class ShipmentController extends Controller
{
    use ResolvesCompany;

    public function __construct(private readonly ShipmentService $shipments) {}

    /** Active + recently completed shipments for the company. */
    public function index(Request $request)
    {
        $company = $this->company($request);

        // Self-healing: settle any deliveries that have reached their ETA so the
        // list never shows a truck stuck at "arriving…" even if the world cron
        // hasn't ticked recently.
        $this->shipments->resolveDueFor($company);

        $shipments = Shipment::where('company_id', $company->id)
            ->with(['contract.commodity', 'contract.origin', 'contract.destination', 'vehicle.model', 'driver'])
            ->orderByRaw("CASE status WHEN 'en_route' THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->limit(40)
            ->get();

        return ShipmentResource::collection($shipments);
    }

    /** Dispatch a vehicle + driver against an accepted contract. */
    public function dispatch(Request $request)
    {
        $company = $this->company($request);

        $data = $request->validate([
            'contract_id' => ['required', 'integer'],
            'vehicle_id' => ['required', 'integer'],
            'driver_id' => ['required', 'integer'],
        ]);

        $contract = Contract::where('id', $data['contract_id'])->where('company_id', $company->id)->firstOrFail();
        $vehicle = Vehicle::where('id', $data['vehicle_id'])->where('company_id', $company->id)->firstOrFail();
        $driver = Driver::where('id', $data['driver_id'])->where('company_id', $company->id)->firstOrFail();

        try {
            $shipment = $this->shipments->dispatch($company, $contract, $vehicle, $driver);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return (new ShipmentResource(
            $shipment->load(['contract.commodity', 'contract.origin', 'contract.destination', 'vehicle.model', 'driver'])
        ))->response()->setStatusCode(201);
    }
}
