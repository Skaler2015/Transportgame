<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesCompany;
use App\Http\Controllers\Controller;
use App\Http\Resources\ContractResource;
use App\Models\Contract;
use App\Services\CompanyService;
use Illuminate\Http\Request;
use RuntimeException;

class ContractController extends Controller
{
    use ResolvesCompany;

    public function __construct(private readonly CompanyService $companies) {}

    /** The open contract market, filterable and sortable. */
    public function index(Request $request)
    {
        $filters = $request->validate([
            'origin_city_id' => ['nullable', 'integer', 'exists:cities,id'],
            'destination_city_id' => ['nullable', 'integer', 'exists:cities,id'],
            'commodity_id' => ['nullable', 'integer', 'exists:commodities,id'],
            'sort' => ['nullable', 'in:payout,distance_km,difficulty,deadline_at'],
        ]);

        $query = Contract::onMarket()->with(['commodity', 'origin', 'destination']);

        foreach (['origin_city_id', 'destination_city_id', 'commodity_id'] as $f) {
            if (! empty($filters[$f])) {
                $query->where($f, $filters[$f]);
            }
        }

        $sort = $filters['sort'] ?? 'payout';
        $query->orderByDesc($sort === 'payout' ? 'payout' : $sort);
        if ($sort !== 'payout') {
            $query->reorder()->orderBy($sort);
        }

        return ContractResource::collection($query->limit(60)->get());
    }

    /** Contracts this company has accepted or is running. */
    public function mine(Request $request)
    {
        $company = $this->company($request);

        $contracts = Contract::where('company_id', $company->id)
            ->whereIn('status', [Contract::STATUS_ACCEPTED, Contract::STATUS_IN_PROGRESS])
            ->with(['commodity', 'origin', 'destination'])
            ->orderByDesc('created_at')
            ->get();

        return ContractResource::collection($contracts);
    }

    /** Claim an open contract. */
    public function accept(Request $request, Contract $contract)
    {
        $company = $this->company($request);

        try {
            $accepted = $this->companies->acceptContract($company, $contract);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return new ContractResource($accepted);
    }
}
