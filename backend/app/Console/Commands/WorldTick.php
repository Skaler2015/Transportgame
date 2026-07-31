<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Shipment;
use App\Models\TradeListing;
use App\Services\EconomyService;
use App\Services\EventService;
use App\Services\FinanceService;
use App\Services\MissionService;
use App\Services\ShipmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Advances the persistent Transoria world by one tick. Intended to run on a
 * schedule (see routes/console.php) but is safe to invoke by hand for local
 * play/testing. Ordered so shipments settle against fresh prices.
 */
class WorldTick extends Command
{
    protected $signature = 'world:tick {--quiet-summary : Suppress the per-tick summary table}';

    protected $description = 'Advance the Transoria economy, events and shipments by one tick';

    public function handle(
        EconomyService $economy,
        EventService $events,
        ShipmentService $shipments,
        FinanceService $finance,
        MissionService $missions,
    ): int {
        $start = microtime(true);

        // 1. World events + weather/fuel drift.
        $spawned = $events->maybeSpawn();
        $activeEvents = $events->active();
        $events->driftCities($activeEvents);

        // 2. Markets: move stock, recompute prices, record history.
        $marketRows = $economy->tickMarkets($activeEvents);

        // 3. Settle every shipment whose ETA has passed.
        $arrived = 0;
        Shipment::where('status', Shipment::STATUS_EN_ROUTE)
            ->where('eta_at', '<=', now())
            ->orderBy('eta_at')
            ->chunkById(100, function ($batch) use ($shipments, &$arrived) {
                foreach ($batch as $shipment) {
                    $shipments->resolve($shipment);
                    $arrived++;
                }
            });

        // 4. Per-company upkeep: rest drivers, accrue loan interest, top up missions.
        DB::table('companies')->orderBy('id')->pluck('id')->each(function ($id) use ($shipments, $finance, $missions) {
            $company = Company::find($id);
            if (! $company) {
                return;
            }
            $shipments->restDrivers($company);
            $finance->accrueInterest($company);
            $missions->ensure($company);
        });

        // 5. Contract market housekeeping + expire stale exchange listings.
        $expired = $economy->expireStaleContracts();
        $minted = $economy->replenishContracts($activeEvents);
        TradeListing::where('status', TradeListing::STATUS_OPEN)
            ->where('expires_at', '<=', now())
            ->update(['status' => TradeListing::STATUS_CANCELLED]);

        $ms = round((microtime(true) - $start) * 1000);

        if (! $this->option('quiet-summary')) {
            $this->table(
                ['metric', 'value'],
                [
                    ['markets updated', $marketRows],
                    ['shipments settled', $arrived],
                    ['contracts minted', $minted],
                    ['contracts expired', $expired],
                    ['event spawned', $spawned?->title ?? '—'],
                    ['active events', $activeEvents->count()],
                    ['elapsed', "{$ms} ms"],
                ]
            );
        }

        return self::SUCCESS;
    }
}
