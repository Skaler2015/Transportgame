<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesCompany;
use App\Http\Controllers\Controller;
use App\Http\Resources\TrailerModelResource;
use App\Http\Resources\TrailerResource;
use App\Models\Trailer;
use App\Models\TrailerModel;
use App\Services\CompanyService;
use Illuminate\Http\Request;
use RuntimeException;

class TrailerController extends Controller
{
    use ResolvesCompany;

    public function __construct(private readonly CompanyService $companies) {}

    /** Trailers the company owns. */
    public function index(Request $request)
    {
        $company = $this->company($request);

        $trailers = Trailer::where('company_id', $company->id)
            ->with(['model', 'city'])
            ->orderBy('status')
            ->get();

        return TrailerResource::collection($trailers);
    }

    /** The trailer dealership catalogue. */
    public function dealership(Request $request)
    {
        return TrailerModelResource::collection(
            TrailerModel::orderBy('unlock_level')->orderBy('price')->get()
        );
    }

    /** Buy a trailer from the dealership. */
    public function buy(Request $request, TrailerModel $model)
    {
        $company = $this->company($request);

        try {
            $trailer = $this->companies->buyTrailer($company, $model);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            // Surface the real reason (schema drift, etc.) instead of a bare 500,
            // and log it so it shows up server-side too.
            \Illuminate\Support\Facades\Log::error('Trailer purchase failed: '.$e->getMessage());

            return response()->json([
                'message' => 'Could not buy trailer: '.$e->getMessage(),
            ], 500);
        }

        return (new TrailerResource($trailer->load('model', 'city')))
            ->response()->setStatusCode(201);
    }
}
