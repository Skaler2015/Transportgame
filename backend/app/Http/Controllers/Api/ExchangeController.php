<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesCompany;
use App\Http\Controllers\Controller;
use App\Models\Commodity;
use App\Models\TradeListing;
use App\Models\Warehouse;
use App\Services\TradeService;
use Illuminate\Http\Request;
use RuntimeException;

class ExchangeController extends Controller
{
    use ResolvesCompany;

    public function __construct(private readonly TradeService $trade) {}

    /** Open listings from all players + the current player's own listings. */
    public function index(Request $request)
    {
        $company = $this->company($request);

        $open = TradeListing::open()
            ->with(['seller', 'commodity', 'city'])
            ->orderBy('price_per_unit')->limit(80)->get()
            ->map(fn (TradeListing $l) => $this->present($l, $company->id));

        $mine = TradeListing::where('seller_company_id', $company->id)
            ->where('status', TradeListing::STATUS_OPEN)
            ->with(['commodity', 'city'])->get()
            ->map(fn (TradeListing $l) => $this->present($l, $company->id));

        return response()->json(['listings' => $open, 'mine' => $mine]);
    }

    public function create(Request $request)
    {
        $company = $this->company($request);
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer'],
            'commodity_id' => ['required', 'integer', 'exists:commodities,id'],
            'units' => ['required', 'integer', 'min:1'],
            'price_per_unit' => ['required', 'numeric', 'min:0.01'],
        ]);

        $warehouse = Warehouse::where('id', $data['warehouse_id'])
            ->where('company_id', $company->id)->firstOrFail();

        try {
            $this->trade->list($company, $warehouse, Commodity::findOrFail($data['commodity_id']),
                $data['units'], (float) $data['price_per_unit']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Listing posted to the exchange.'], 201);
    }

    public function buy(Request $request, TradeListing $listing)
    {
        $company = $this->company($request);
        try {
            $this->trade->buy($company, $listing);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Purchase complete — goods delivered to your warehouse.']);
    }

    public function cancel(Request $request, TradeListing $listing)
    {
        $company = $this->company($request);
        try {
            $this->trade->cancel($company, $listing);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Listing cancelled.']);
    }

    private function present(TradeListing $l, int $companyId): array
    {
        return [
            'id' => $l->id,
            'commodity' => $l->commodity?->name,
            'city' => $l->city?->name,
            'units' => $l->units,
            'price_per_unit' => (float) $l->price_per_unit,
            'total' => $l->totalCents(),
            'seller' => $l->relationLoaded('seller') ? $l->seller?->name : null,
            'is_mine' => $l->seller_company_id === $companyId,
            'expires_at' => $l->expires_at?->toIso8601String(),
        ];
    }
}
