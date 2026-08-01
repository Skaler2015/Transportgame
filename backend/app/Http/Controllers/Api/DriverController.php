<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesCompany;
use App\Http\Controllers\Controller;
use App\Http\Resources\DriverResource;
use App\Models\Driver;
use App\Services\CompanyService;
use App\Services\DriverService;
use App\Services\ShipmentService;
use Illuminate\Http\Request;
use RuntimeException;

class DriverController extends Controller
{
    use ResolvesCompany;

    public function __construct(
        private readonly CompanyService $companies,
        private readonly ShipmentService $shipments,
        private readonly DriverService $drivers,
    ) {}

    /** HR action: train | licence | vacation. */
    public function action(Request $request, Driver $driver)
    {
        $company = $this->company($request);
        $data = $request->validate(['action' => ['required', 'in:train,licence,vacation']]);

        try {
            $result = match ($data['action']) {
                'train' => $this->drivers->train($company, $driver),
                'licence' => $this->drivers->renewLicence($company, $driver),
                'vacation' => $this->drivers->vacation($company, $driver),
            };
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => $result['message']]);
    }

    public function index(Request $request)
    {
        $company = $this->company($request);

        // Recover rested crews between trips even without the world cron.
        $this->shipments->restDriversThrottled($company);

        $drivers = Driver::where('company_id', $company->id)
            ->orderByDesc('skill')
            ->get();

        // Surface HR action prices so the UI can label its buttons without
        // hard-coding figures that could drift from config.
        $cfg = config('transoria.driver');

        return DriverResource::collection($drivers)->additional([
            'costs' => [
                'hire' => 3500_00,
                'train' => (int) ($cfg['train_cost'] ?? 0),
                'licence' => (int) ($cfg['licence_cost'] ?? 0),
                'vacation' => (int) ($cfg['vacation_cost'] ?? 0),
                'licence_days' => (int) ($cfg['licence_days'] ?? 30),
            ],
        ]);
    }

    /** Hire a new driver from the labour market. */
    public function hire(Request $request)
    {
        $company = $this->company($request);

        try {
            $driver = $this->companies->hireDriver($company);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return (new DriverResource($driver))->response()->setStatusCode(201);
    }
}
