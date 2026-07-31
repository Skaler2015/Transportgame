<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesCompany;
use App\Http\Controllers\Controller;
use App\Http\Resources\DriverResource;
use App\Models\Driver;
use App\Services\CompanyService;
use Illuminate\Http\Request;
use RuntimeException;

class DriverController extends Controller
{
    use ResolvesCompany;

    public function __construct(private readonly CompanyService $companies) {}

    public function index(Request $request)
    {
        $company = $this->company($request);

        $drivers = Driver::where('company_id', $company->id)
            ->orderByDesc('skill')
            ->get();

        return DriverResource::collection($drivers);
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
