<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesCompany;
use App\Http\Controllers\Controller;
use App\Http\Resources\ShipmentResource;
use App\Models\Contract;
use App\Models\Driver;
use App\Models\LedgerEntry;
use App\Models\Shipment;
use App\Models\Trailer;
use App\Models\Vehicle;
use App\Services\ShipmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            ->with(['contract.commodity', 'contract.origin', 'contract.destination', 'vehicle.model', 'trailer.model', 'driver'])
            ->orderByRaw("CASE status WHEN 'en_route' THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->limit(40)
            ->get();

        // Today's performance snapshot for the On-the-Road panel.
        $todayStart = now()->startOfDay();

        $statusCounts = Shipment::where('company_id', $company->id)
            ->where('arrived_at', '>=', $todayStart)
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $onTime = (int) ($statusCounts[Shipment::STATUS_DELIVERED] ?? 0);
        $late = (int) ($statusCounts[Shipment::STATUS_LATE] ?? 0);
        $failed = (int) ($statusCounts[Shipment::STATUS_FAILED] ?? 0);

        // Delivery earnings today (net of tax) from the ledger.
        $revenueToday = (int) LedgerEntry::where('company_id', $company->id)
            ->where('occurred_at', '>=', $todayStart)
            ->where(fn ($q) => $q->where('description', 'like', 'Delivered:%')
                ->orWhere('description', 'like', 'Late delivery:%'))
            ->sum('amount');

        // The lane that earned the most today.
        $best = DB::table('shipments')
            ->join('contracts', 'shipments.contract_id', '=', 'contracts.id')
            ->join('cities as oc', 'contracts.origin_city_id', '=', 'oc.id')
            ->join('cities as dc', 'contracts.destination_city_id', '=', 'dc.id')
            ->where('shipments.company_id', $company->id)
            ->where('shipments.arrived_at', '>=', $todayStart)
            ->whereIn('shipments.status', [Shipment::STATUS_DELIVERED, Shipment::STATUS_LATE])
            ->groupBy('oc.name', 'dc.name')
            ->selectRaw('oc.name as origin, dc.name as destination, sum(shipments.projected_payout) as total, count(*) as trips')
            ->orderByDesc('total')
            ->first();

        return ShipmentResource::collection($shipments)->additional([
            'delivered_today' => $onTime + $late,
            'today' => [
                'on_time' => $onTime,
                'late' => $late,
                'failed' => $failed,
                'revenue' => $revenueToday,
                'best_route' => $best ? [
                    'label' => $best->origin.' → '.$best->destination,
                    'amount' => (int) $best->total,
                    'trips' => (int) $best->trips,
                ] : null,
            ],
        ]);
    }

    /** Dispatch a vehicle + driver against an accepted contract. */
    public function dispatch(Request $request)
    {
        $company = $this->company($request);

        $data = $request->validate([
            'contract_id' => ['required', 'integer'],
            'vehicle_id' => ['required', 'integer'],
            'driver_id' => ['required', 'integer'],
            'trailer_id' => ['nullable', 'integer'],
        ]);

        $contract = Contract::where('id', $data['contract_id'])->where('company_id', $company->id)->firstOrFail();
        $vehicle = Vehicle::where('id', $data['vehicle_id'])->where('company_id', $company->id)->firstOrFail();
        $driver = Driver::where('id', $data['driver_id'])->where('company_id', $company->id)->firstOrFail();

        $trailer = null;
        if (! empty($data['trailer_id'])) {
            $trailer = Trailer::where('id', $data['trailer_id'])->where('company_id', $company->id)->firstOrFail();
        }

        try {
            $shipment = $this->shipments->dispatch($company, $contract, $vehicle, $driver, $trailer);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return (new ShipmentResource(
            $shipment->load(['contract.commodity', 'contract.origin', 'contract.destination', 'vehicle.model', 'trailer.model', 'driver'])
        ))->response()->setStatusCode(201);
    }
}
