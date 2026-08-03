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

    public const UPGRADES = ['expand', 'cold', 'hazmat', 'automation', 'staff', 'security'];

    /**
     * Upgrade a warehouse: expand a tier, or add cold storage / a hazmat bay /
     * automation / staff / security.
     *
     * @return array{warehouse: Warehouse, message: string}
     */
    public function upgrade(Company $company, Warehouse $warehouse, string $type): array
    {
        if ($warehouse->company_id !== $company->id) {
            throw new RuntimeException('That warehouse is not yours.');
        }
        if (! in_array($type, self::UPGRADES, true)) {
            throw new RuntimeException('Unknown upgrade.');
        }

        $cfg = config('transoria.warehouse');

        [$cost, $label, $apply] = match ($type) {
            'expand' => (function () use ($warehouse, $cfg) {
                if ($warehouse->tier >= $cfg['max_tier']) {
                    throw new RuntimeException('This warehouse is already at maximum size.');
                }

                return [(int) $cfg['expand_cost'] * $warehouse->tier, 'Expanded warehouse', function (Warehouse $w) use ($cfg) {
                    $w->capacity += (int) $cfg['capacity_per_tier'];
                    $w->upkeep += (int) $cfg['upkeep_per_tier'];
                    $w->tier += 1;
                }];
            })(),
            'cold' => $this->onceOff($warehouse, 'cold_storage', (int) $cfg['cold_cost'], 'Added cold storage'),
            'hazmat' => $this->onceOff($warehouse, 'hazmat_certified', (int) $cfg['hazmat_cost'], 'Added a hazmat bay'),
            'automation' => $this->onceOff($warehouse, 'automated', (int) $cfg['automation_cost'], 'Automated the warehouse'),
            'staff' => $this->levelled($warehouse, 'staff_level', (int) $cfg['max_staff_level'], (int) $cfg['staff_cost'], 'Hired workers & forklifts'),
            'security' => $this->levelled($warehouse, 'security_level', (int) $cfg['max_security_level'], (int) $cfg['security_cost'], 'Upgraded security'),
        };

        if ($company->cash < $cost) {
            throw new RuntimeException('Not enough cash for this upgrade.');
        }

        $this->ledger->post($company, LedgerEntry::CAT_PURCHASE,
            "{$label}: {$warehouse->name}", -$cost, $warehouse);
        $apply($warehouse);
        $warehouse->save();

        return ['warehouse' => $warehouse, 'message' => "{$label}."];
    }

    /** Helper for a one-off boolean facility. */
    private function onceOff(Warehouse $warehouse, string $flag, int $cost, string $label): array
    {
        if ($warehouse->$flag) {
            throw new RuntimeException('This warehouse already has that.');
        }

        return [$cost, $label, function (Warehouse $w) use ($flag) {
            $w->$flag = true;
        }];
    }

    /** Helper for a levelled facility (staff, security). */
    private function levelled(Warehouse $warehouse, string $column, int $max, int $baseCost, string $label): array
    {
        $current = (int) $warehouse->$column;
        if ($current >= $max) {
            throw new RuntimeException('That facility is already maxed.');
        }

        return [$baseCost * ($current + 1), $label, function (Warehouse $w) use ($column, $current) {
            $w->$column = $current + 1;
        }];
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
        if (! $warehouse->canStore($commodity)) {
            $need = $commodity->is_perishable ? 'cold storage' : 'a hazmat bay';
            throw new RuntimeException("This warehouse needs {$need} to store {$commodity->name}.");
        }
        if ($warehouse->usedCapacity() + $units > $warehouse->effectiveCapacity()) {
            throw new RuntimeException('Not enough warehouse space.');
        }

        $price = $this->economy->latestPrice($warehouse->city_id, $commodity)
            * (1 + $warehouse->effectiveSpread('buy_spread'));
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
            * (1 - $warehouse->effectiveSpread('sell_spread'));
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
