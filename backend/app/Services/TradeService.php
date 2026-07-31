<?php

namespace App\Services;

use App\Models\Commodity;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\TradeListing;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Player-to-player commodity exchange. Sellers escrow warehouse stock into a
 * listing; buyers pay and receive the goods into their own warehouse in the
 * listing's city. A small market fee is taken from the seller's proceeds.
 */
class TradeService
{
    public function __construct(private readonly LedgerService $ledger) {}

    public function list(Company $company, Warehouse $warehouse, Commodity $commodity, int $units, float $pricePerUnit): TradeListing
    {
        if ($warehouse->company_id !== $company->id) {
            throw new RuntimeException('That warehouse is not yours.');
        }
        if ($units < 1 || $pricePerUnit <= 0) {
            throw new RuntimeException('Enter a valid quantity and price.');
        }

        $inv = WarehouseInventory::where('warehouse_id', $warehouse->id)
            ->where('commodity_id', $commodity->id)->first();
        if (! $inv || $inv->units < $units) {
            throw new RuntimeException('Not enough stock in that warehouse.');
        }

        return DB::transaction(function () use ($company, $warehouse, $commodity, $units, $pricePerUnit, $inv) {
            // Escrow the units out of the warehouse.
            $inv->units -= $units;
            $inv->units <= 0 ? $inv->delete() : $inv->save();

            return TradeListing::create([
                'seller_company_id' => $company->id,
                'commodity_id' => $commodity->id,
                'city_id' => $warehouse->city_id,
                'units' => $units,
                'price_per_unit' => round($pricePerUnit, 2),
                'status' => TradeListing::STATUS_OPEN,
                'expires_at' => now()->addHours(config('transoria.exchange.listing_ttl_hours')),
            ]);
        });
    }

    public function buy(Company $buyer, TradeListing $listing): TradeListing
    {
        if ($listing->status !== TradeListing::STATUS_OPEN || $listing->expires_at->isPast()) {
            throw new RuntimeException('That listing is no longer available.');
        }
        if ($listing->seller_company_id === $buyer->id) {
            throw new RuntimeException('You cannot buy your own listing.');
        }

        $total = $listing->totalCents();
        if ($buyer->cash < $total) {
            throw new RuntimeException('Not enough cash for this purchase.');
        }

        // Buyer must have a warehouse with room in the listing's city.
        $warehouse = Warehouse::where('company_id', $buyer->id)
            ->where('city_id', $listing->city_id)->get()
            ->first(fn (Warehouse $w) => $w->usedCapacity() + $listing->units <= $w->capacity);

        if (! $warehouse) {
            throw new RuntimeException('You need a warehouse with free space in that city to receive the goods.');
        }

        return DB::transaction(function () use ($buyer, $listing, $total, $warehouse) {
            $seller = Company::lockForUpdate()->find($listing->seller_company_id);
            $fee = (int) round($total * config('transoria.exchange.fee_pct'));

            // Buyer pays, seller receives net of fee.
            $this->ledger->post($buyer, LedgerEntry::CAT_PURCHASE,
                "Bought {$listing->units}× {$listing->commodity->name} (exchange)", -$total, $listing);
            if ($seller) {
                $this->ledger->post($seller, LedgerEntry::CAT_REVENUE,
                    "Sold {$listing->units}× {$listing->commodity->name} (exchange)", $total - $fee, $listing);
            }

            // Deliver goods into buyer's warehouse.
            $row = WarehouseInventory::firstOrNew([
                'warehouse_id' => $warehouse->id,
                'commodity_id' => $listing->commodity_id,
            ]);
            $existingValue = $row->exists ? $row->avg_unit_cost * $row->units : 0;
            $newUnits = ($row->units ?? 0) + $listing->units;
            $row->units = $newUnits;
            $row->avg_unit_cost = round(($existingValue + $listing->price_per_unit * $listing->units) / max(1, $newUnits), 2);
            $row->save();

            $listing->status = TradeListing::STATUS_SOLD;
            $listing->buyer_company_id = $buyer->id;
            $listing->save();

            return $listing;
        });
    }

    public function cancel(Company $company, TradeListing $listing): void
    {
        if ($listing->seller_company_id !== $company->id) {
            throw new RuntimeException('That listing is not yours.');
        }
        if ($listing->status !== TradeListing::STATUS_OPEN) {
            throw new RuntimeException('That listing cannot be cancelled.');
        }

        // Return escrowed units to any warehouse the seller has in that city.
        $warehouse = Warehouse::where('company_id', $company->id)
            ->where('city_id', $listing->city_id)->first();
        if (! $warehouse) {
            throw new RuntimeException('You no longer have a warehouse in that city to reclaim the goods.');
        }

        DB::transaction(function () use ($listing, $warehouse) {
            $row = WarehouseInventory::firstOrNew([
                'warehouse_id' => $warehouse->id,
                'commodity_id' => $listing->commodity_id,
            ]);
            $row->units = ($row->units ?? 0) + $listing->units;
            $row->avg_unit_cost = $row->avg_unit_cost ?: $listing->price_per_unit;
            $row->save();

            $listing->status = TradeListing::STATUS_CANCELLED;
            $listing->save();
        });
    }
}
