<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesCompany;
use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use App\Services\GarageService;
use Illuminate\Http\Request;
use RuntimeException;

class GarageController extends Controller
{
    use ResolvesCompany;

    public function __construct(private readonly GarageService $garage) {}

    public function repair(Request $request, Vehicle $vehicle)
    {
        $company = $this->company($request);
        try {
            $this->garage->repair($company, $vehicle);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Vehicle fully serviced.']);
    }

    public function upgrade(Request $request, Vehicle $vehicle)
    {
        $company = $this->company($request);
        $data = $request->validate([
            'kind' => ['required', 'in:engine,tires,trailer'],
        ]);

        try {
            $this->garage->upgrade($company, $vehicle, $data['kind']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => ucfirst($data['kind']).' upgraded.']);
    }
}
