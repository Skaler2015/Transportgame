<?php

namespace App\Services;

use App\Models\Commodity;
use App\Models\Company;
use App\Models\Factory;
use App\Models\LedgerEntry;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Manufacturing: player-built factories that turn cash (and, for real supply
 * chains, the outputs of other factories) into finished goods stored at a
 * warehouse — ready to sell locally or haul to a dearer market.
 *
 * Production is stepped lazily during requests (rate-limited) and by the
 * scheduled world tick, in whole cycles of {@see cycleSeconds()} game-seconds,
 * with bounded catch-up so a quiet spell can't spike one request.
 */
class ManufacturingService
{
    public function __construct(
        private readonly LedgerService $ledger,
    ) {}

    public function cycleSeconds(): int
    {
        return (int) config('transoria.manufacturing.cycle_seconds', 300);
    }

    /** Recipes annotated for one company: unlocked?, affordable?, output name. */
    public function catalog(Company $company): array
    {
        $commodities = Commodity::all()->keyBy('key');

        return collect(config('transoria.manufacturing.recipes', []))
            ->map(function ($r, $key) use ($company, $commodities) {
                $out = $commodities[$r['output']] ?? null;

                return [
                    'recipe' => $key,
                    'name' => $r['name'],
                    'icon' => $r['icon'],
                    'output' => $out?->name ?? $r['output'],
                    'output_qty' => $r['output_qty'],
                    'inputs' => collect($r['inputs'] ?? [])->map(fn ($qty, $k) => [
                        'commodity' => $commodities[$k]?->name ?? $k,
                        'qty' => $qty,
                    ])->values(),
                    'op_cost' => (int) $r['op_cost'],
                    'build_cost' => (int) $r['build_cost'],
                    'upkeep' => (int) $r['upkeep'],
                    'unlock' => (int) $r['unlock'],
                    'needs' => $r['needs'] ?? null,
                    'unlocked' => $company->level >= (int) $r['unlock'],
                    'affordable' => $company->cash >= (int) $r['build_cost'],
                ];
            })->values()->all();
    }

    public function build(Company $company, Warehouse $warehouse, string $recipe): Factory
    {
        $cfg = config('transoria.manufacturing.recipes.'.$recipe);
        if (! $cfg) {
            throw new RuntimeException('Unknown factory type.');
        }
        if ($warehouse->company_id !== $company->id) {
            throw new RuntimeException('That warehouse is not yours.');
        }
        if ($company->level < (int) $cfg['unlock']) {
            throw new RuntimeException("Reach level {$cfg['unlock']} to build a {$cfg['name']}.");
        }
        if ($company->cash < (int) $cfg['build_cost']) {
            throw new RuntimeException('Not enough cash to build this factory.');
        }

        return DB::transaction(function () use ($company, $warehouse, $recipe, $cfg) {
            $this->ledger->post($company, LedgerEntry::CAT_PURCHASE,
                "Built {$cfg['name']} at {$warehouse->name}", -(int) $cfg['build_cost'], $warehouse);

            return Factory::create([
                'company_id' => $company->id,
                'warehouse_id' => $warehouse->id,
                'recipe' => $recipe,
                'level' => 1,
                'status' => Factory::STATUS_ACTIVE,
                'last_produced_at' => now(),
            ]);
        });
    }

    public function upgrade(Company $company, Factory $factory): Factory
    {
        if ($factory->company_id !== $company->id) {
            throw new RuntimeException('That factory is not yours.');
        }
        $max = (int) config('transoria.manufacturing.max_level', 5);
        if ($factory->level >= $max) {
            throw new RuntimeException('This factory is already at maximum level.');
        }
        $cost = $this->upgradeCost($factory);
        if ($company->cash < $cost) {
            throw new RuntimeException('Not enough cash to upgrade this factory.');
        }

        DB::transaction(function () use ($company, $factory, $cost) {
            $this->ledger->post($company, LedgerEntry::CAT_PURCHASE,
                "Upgraded {$factory->recipeConfig()['name']} to L".($factory->level + 1), -$cost, $factory->warehouse);
            $factory->increment('level');
        });

        return $factory->refresh();
    }

    public function upgradeCost(Factory $factory): int
    {
        $cfg = $factory->recipeConfig();
        $mult = (float) config('transoria.manufacturing.upgrade_cost_mult', 0.75);

        return (int) round(($cfg['build_cost'] ?? 0) * $mult * $factory->level);
    }

    public function toggle(Company $company, Factory $factory): Factory
    {
        if ($factory->company_id !== $company->id) {
            throw new RuntimeException('That factory is not yours.');
        }
        $factory->status = $factory->status === Factory::STATUS_ACTIVE
            ? Factory::STATUS_PAUSED : Factory::STATUS_ACTIVE;
        $factory->save();

        return $factory;
    }

    /**
     * Lazy production step for one company's factories. Rate-limited so only
     * one request every few seconds actually runs the cycles.
     */
    public function produceDue(Company $company): void
    {
        if (! Cache::add("mfg:{$company->id}", 1, now()->addSeconds(15))) {
            return;
        }

        $factories = Factory::where('company_id', $company->id)
            ->where('status', Factory::STATUS_ACTIVE)
            ->with('warehouse')->get();

        foreach ($factories as $factory) {
            $this->stepFactory($company, $factory);
        }
    }

    /** Advance one factory through its due cycles (bounded catch-up). */
    protected function stepFactory(Company $company, Factory $factory): void
    {
        $cycleSecs = $this->cycleSeconds();
        $last = $factory->last_produced_at ?? now();
        $elapsed = now()->getTimestamp() - $last->getTimestamp();
        $cycles = (int) floor($elapsed / $cycleSecs);
        if ($cycles <= 0) {
            return;
        }
        $cycles = min($cycles, (int) config('transoria.manufacturing.max_catchup_cycles', 12));

        $produced = 0;
        $note = null;
        for ($i = 0; $i < $cycles; $i++) {
            [$ok, $note] = $this->runCycle($company, $factory);
            if (! $ok) {
                break; // blocked (no inputs / cash / space) — stop this pass
            }
            $produced++;
        }

        // Advance the clock by whatever we processed; if blocked immediately,
        // still move it forward so backlog can't grow without bound.
        $factory->last_produced_at = $produced > 0
            ? $last->copy()->addSeconds($produced * $cycleSecs)
            : now();
        $factory->last_note = $note;
        $factory->save();
    }

    /**
     * Run a single production cycle. Returns [produced?, note]. A cycle needs:
     * the input commodities in the warehouse, cash for the op-cost, warehouse
     * space for the output, and a warehouse that can legally store it.
     */
    protected function runCycle(Company $company, Factory $factory): array
    {
        $cfg = $factory->recipeConfig();
        if (! $cfg) {
            return [false, 'Recipe no longer exists.'];
        }
        $warehouse = $factory->warehouse;
        if (! $warehouse) {
            return [false, 'Warehouse missing.'];
        }

        $mult = $factory->levelMultiplier();
        $outCommodity = Commodity::where('key', $cfg['output'])->first();
        if (! $outCommodity) {
            return [false, 'Output commodity missing.'];
        }
        if (! $warehouse->canStore($outCommodity)) {
            $need = $outCommodity->is_perishable ? 'cold storage' : 'a hazmat bay';
            return [false, "Warehouse needs {$need} for {$outCommodity->name}."];
        }

        $outUnits = (int) round($cfg['output_qty'] * $mult);
        if ($warehouse->usedCapacity() + $outUnits > $warehouse->effectiveCapacity()) {
            return [false, 'Warehouse is full — sell or haul stock.'];
        }

        // Gather inputs (scaled by level) and their cost basis.
        $inputRows = [];
        $inputBasis = 0.0; // ₡
        foreach (($cfg['inputs'] ?? []) as $key => $qty) {
            $needUnits = (int) ceil($qty * $mult);
            $inCommodity = Commodity::where('key', $key)->first();
            if (! $inCommodity) {
                return [false, "Input {$key} missing."];
            }
            $row = WarehouseInventory::where('warehouse_id', $warehouse->id)
                ->where('commodity_id', $inCommodity->id)->first();
            if (! $row || $row->units < $needUnits) {
                return [false, "Needs {$needUnits}× {$inCommodity->name} in this warehouse."];
            }
            $inputRows[] = [$row, $needUnits];
            $inputBasis += $row->avg_unit_cost * $needUnits;
        }

        $opCost = (int) round($cfg['op_cost'] * $mult); // cents
        if ($company->cash < $opCost) {
            return [false, 'Not enough cash for the production run.'];
        }

        DB::transaction(function () use ($company, $factory, $warehouse, $outCommodity, $outUnits, $inputRows, $inputBasis, $opCost, $cfg) {
            // Consume inputs.
            foreach ($inputRows as [$row, $needUnits]) {
                $row->decrement('units', $needUnits);
            }

            // Charge the cash op-cost.
            $this->ledger->post($company, LedgerEntry::CAT_PURCHASE,
                "Produced {$outUnits}× {$outCommodity->name} ({$cfg['name']})", -$opCost, $factory);

            // Deposit output at a true unit cost (inputs' basis + op-cost).
            $unitCost = round(($inputBasis + $opCost / 100) / max(1, $outUnits), 2);
            $out = WarehouseInventory::firstOrNew([
                'warehouse_id' => $warehouse->id,
                'commodity_id' => $outCommodity->id,
            ]);
            $existingValue = $out->exists ? $out->avg_unit_cost * $out->units : 0;
            $newUnits = ($out->units ?? 0) + $outUnits;
            $out->units = $newUnits;
            $out->avg_unit_cost = round(($existingValue + $unitCost * $outUnits) / max(1, $newUnits), 2);
            $out->save();

            $factory->increment('lifetime_output', $outUnits);
        });

        return [true, null];
    }
}
