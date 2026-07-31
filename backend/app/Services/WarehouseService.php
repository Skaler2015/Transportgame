<?php

namespace App\Services;

use App\Models\City;
use App\Models\Commodity;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Warehouses & direct commodity trading. Lets a company buy goods at a city's
 * local price into storage and sell them later when the price moves — the
 * arbitrage layer that sits alongside contract haulage.
 */
class WarehouseService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly EconomyService $economy,
    ) {}

    public function build(Company $company, City $city, string $name): Warehouse
    {
        $cost = config('transoria.warehouse.build_cost');
        if ($company->cash < $cost) {
            throw new RuntimeException('Not enough cash to build a warehouse.');
        }

        $warehouse = Warehouse::create([
            'company_id' => $company->id,
            'city_id' => $city->id,
            'name' => $name ?: ($city->name.' Depot'),
            'capacity' => config('transoria.warehouse.base_capacity'),
            'tier' => 1,
            'upkeep' => 500_00,
        ]);

        $this->ledger->post($company, LedgerEntry::CAT_PURCHASE,
            "Built warehouse in {$city->name}", -$cost, $warehouse);

        return $warehouse;
    }

    /** Buy units of a commodity into a warehouse at the local ask price. */
    public function buy(Company $company, Warehouse $warehouse, Commodity $commodity, int $units): WarehouseInventory
    {
        if ($units < 1) {
            throw new RuntimeException('Enter a quantity of at least 1.');
        }
        if ($warehouse->company_id !== $company->id) {
            throw new RuntimeException('That warehouse is not yours.');
        }
        if ($warehouse->usedCapacity() + $units > $warehouse->capacity) {
            throw new RuntimeException('Not enough warehouse space.');
        }

        $price = $this->economy->latestPrice($warehouse->city_id, $commodity)
            * (1 + config('transoria.warehouse.buy_spread'));
        $costCents = (int) round($price * $units * 100);

        if ($company->cash < $costCents) {
            throw new RuntimeException('Not enough cash for this purchase.');
        }

        return DB::transaction(function () use ($company, $warehouse, $commodity, $units, $price, $costCents) {
            $row = WarehouseInventory::firstOrNew([
                'warehouse_id' => $warehouse->id,
                'commodity_id' => $commodity->id,
            ]);

            // Weighted average cost.
            $existingValue = $row->exists ? $row->avg_unit_cost * $row->units : 0;
            $newUnits = ($row->units ?? 0) + $units;
            $row->units = $newUnits;
            $row->avg_unit_cost = round(($existingValue + $price * $units) / max(1, $newUnits), 2);
            $row->save();

            $this->ledger->post($company, LedgerEntry::CAT_PURCHASE,
                "Bought {$units}× {$commodity->name}", -$costCents, $warehouse);

            // Player buying drains local stock a little.
            DB::table('city_commodity')->where('city_id', $warehouse->city_id)
                ->where('commodity_id', $commodity->id)->decrement('stock', $units);

            return $row;
        });
    }

    /** Sell units from a warehouse at the local bid price. */
    public function sell(Company $company, Warehouse $warehouse, Commodity $commodity, int $units): array
    {
        if ($units < 1) {
            throw new RuntimeException('Enter a quantity of at least 1.');
        }
        if ($warehouse->company_id !== $company->id) {
            throw new RuntimeException('That warehouse is not yours.');
        }

        $row = WarehouseInventory::where('warehouse_id', $warehouse->id)
            ->where('commodity_id', $commodity->id)->first();

        if (! $row || $row->units < $units) {
            throw new RuntimeException('You do not hold that many units.');
        }

        $price = $this->economy->latestPrice($warehouse->city_id, $commodity)
            * (1 - config('transoria.warehouse.sell_spread'));
        $grossCents = (int) round($price * $units * 100);

        return DB::transaction(function () use ($company, $warehouse, $commodity, $units, $grossCents, $row) {
            $row->units -= $units;
            $row->units <= 0 ? $row->delete() : $row->save();

            $this->ledger->post($company, LedgerEntry::CAT_REVENUE,
                "Sold {$units}× {$commodity->name}", $grossCents, $warehouse);

            DB::table('city_commodity')->where('city_id', $warehouse->city_id)
                ->where('commodity_id', $commodity->id)->increment('stock', $units);

            return ['units' => $units, 'gross' => $grossCents];
        });
    }
}
