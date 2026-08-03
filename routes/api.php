<?php

use App\Http\Controllers\Api\AccountsController;
use App\Http\Controllers\Api\AchievementController;
use App\Http\Controllers\Api\AdvisorController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\ContractController;
use App\Http\Controllers\Api\DevController;
use App\Http\Controllers\Api\DriverController;
use App\Http\Controllers\Api\ExchangeController;
use App\Http\Controllers\Api\FinanceController;
use App\Http\Controllers\Api\FleetController;
use App\Http\Controllers\Api\GarageController;
use App\Http\Controllers\Api\GuildController;
use App\Http\Controllers\Api\MarketController;
use App\Http\Controllers\Api\MissionController;
use App\Http\Controllers\Api\NewsController;
use App\Http\Controllers\Api\ResearchController;
use App\Http\Controllers\Api\ShipmentController;
use App\Http\Controllers\Api\StockController;
use App\Http\Controllers\Api\TrailerController;
use App\Http\Controllers\Api\FactoryController;
use App\Http\Controllers\Api\WarehouseController;
use App\Http\Controllers\Api\WorldController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Transoria Online — REST API
|--------------------------------------------------------------------------
| Token auth via Laravel Sanctum. All money fields are integer CENTS of the
| in-game Credit (₡). Protected routes require a Bearer token.
*/

Route::get('/health', fn () => response()->json(['status' => 'ok', 'game' => 'Transoria Online']));

// --- Public auth ---------------------------------------------------------
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Public world reference data (read-only).
Route::get('/world/countries', [WorldController::class, 'countries']);
Route::get('/world/cities', [WorldController::class, 'cities']);
Route::get('/world/commodities', [WorldController::class, 'commodities']);
Route::get('/world/events', [WorldController::class, 'events']);
Route::get('/world/leaderboard', [WorldController::class, 'leaderboard']);
Route::get('/market', [MarketController::class, 'index']);
Route::get('/market/history', [MarketController::class, 'history']);
Route::get('/market/overview', [MarketController::class, 'overview']);

// Non-production helper to advance the simulation on demand.
Route::post('/dev/tick', [DevController::class, 'tick']);

// --- Authenticated -------------------------------------------------------
Route::middleware(['auth:sanctum', 'not_banned'])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // --- Admin panel (operator only) -------------------------------------
    Route::middleware('admin')->prefix('admin')->group(function () {
        Route::get('/dashboard', [AdminController::class, 'dashboard']);
        Route::get('/users', [AdminController::class, 'users']);
        Route::post('/users/{user}/ban', [AdminController::class, 'banUser']);
        Route::post('/users/{user}/admin', [AdminController::class, 'toggleAdmin']);
        Route::get('/events', [AdminController::class, 'events']);
        Route::post('/events', [AdminController::class, 'spawnEvent']);
        Route::post('/events/{event}/end', [AdminController::class, 'endEvent']);
        Route::post('/economy/fuel', [AdminController::class, 'setFuel']);
        Route::post('/tick', [AdminController::class, 'tick']);
        Route::get('/live', [AdminController::class, 'live']);
    });

    Route::get('/company', [CompanyController::class, 'show']);
    Route::post('/company/country', [CompanyController::class, 'updateCountry']);
    Route::post('/company/reset', [CompanyController::class, 'reset']);
    Route::post('/company/tutorial', [CompanyController::class, 'tutorial']);
    Route::get('/dashboard', [CompanyController::class, 'dashboard']);
    Route::get('/ledger', [CompanyController::class, 'ledger']);

    // Automated accounting: P&L, balance sheet, transaction journal.
    Route::get('/accounts', [AccountsController::class, 'summary']);
    Route::get('/accounts/analytics', [AccountsController::class, 'analytics']);
    Route::get('/accounts/ledger', [AccountsController::class, 'ledger']);

    Route::get('/contracts', [ContractController::class, 'index']);
    Route::get('/contracts/mine', [ContractController::class, 'mine']);
    Route::post('/contracts/{contract}/accept', [ContractController::class, 'accept']);
    Route::post('/contracts/{contract}/dispatch', [ContractController::class, 'dispatch']);

    Route::get('/fleet', [FleetController::class, 'index']);
    Route::get('/dealership', [FleetController::class, 'dealership']);
    Route::post('/dealership/{model}/buy', [FleetController::class, 'buy']);
    Route::post('/vehicles/{vehicle}/refuel', [FleetController::class, 'refuel']);
    Route::get('/fleet/service-estimate', [FleetController::class, 'serviceEstimate']);
    Route::post('/fleet/service-all', [FleetController::class, 'serviceAll']);
    Route::post('/fleet/refuel-all', [FleetController::class, 'refuelAll']);

    // Trailers: yard, dealership & purchase.
    Route::get('/trailers', [TrailerController::class, 'index']);
    Route::get('/trailers/dealership', [TrailerController::class, 'dealership']);
    Route::post('/trailers/dealership/{model}/buy', [TrailerController::class, 'buy']);

    Route::get('/drivers', [DriverController::class, 'index']);
    Route::post('/drivers/hire', [DriverController::class, 'hire']);
    Route::post('/drivers/{driver}/action', [DriverController::class, 'action']);

    Route::get('/shipments', [ShipmentController::class, 'index']);
    Route::post('/shipments/dispatch', [ShipmentController::class, 'dispatch']);

    Route::get('/achievements', [AchievementController::class, 'index']);
    Route::get('/news', [NewsController::class, 'index']);
    Route::get('/advisor', [AdvisorController::class, 'index']);

    Route::get('/research', [ResearchController::class, 'index']);
    Route::post('/research/{node}/unlock', [ResearchController::class, 'unlock']);

    // Warehouses & direct trading.
    Route::get('/warehouses', [WarehouseController::class, 'index']);
    Route::post('/warehouses', [WarehouseController::class, 'build']);
    Route::post('/warehouses/{warehouse}/upgrade', [WarehouseController::class, 'upgrade']);
    Route::post('/warehouses/{warehouse}/buy', [WarehouseController::class, 'buy']);
    Route::post('/warehouses/{warehouse}/sell', [WarehouseController::class, 'sell']);

    // Manufacturing: factories that turn cash + inputs into finished goods.
    Route::get('/factories', [FactoryController::class, 'index']);
    Route::post('/factories', [FactoryController::class, 'build']);
    Route::post('/factories/{factory}/upgrade', [FactoryController::class, 'upgrade']);
    Route::post('/factories/{factory}/toggle', [FactoryController::class, 'toggle']);

    // Missions.
    Route::get('/missions', [MissionController::class, 'index']);
    Route::post('/missions/{mission}/claim', [MissionController::class, 'claim']);

    // Garage: repairs & upgrades.
    Route::post('/vehicles/{vehicle}/repair', [GarageController::class, 'repair']);
    Route::post('/vehicles/{vehicle}/upgrade', [GarageController::class, 'upgrade']);
    Route::post('/vehicles/{vehicle}/service', [GarageController::class, 'service']);
    Route::post('/vehicles/{vehicle}/full-service', [GarageController::class, 'fullService']);
    Route::post('/trailers/{trailer}/repair', [GarageController::class, 'repairTrailer']);

    // Finance: loans.
    Route::get('/finance', [FinanceController::class, 'index']);
    Route::post('/finance/borrow', [FinanceController::class, 'borrow']);
    Route::post('/finance/loans/{loan}/repay', [FinanceController::class, 'repay']);

    // Guilds.
    Route::get('/guilds', [GuildController::class, 'index']);
    Route::post('/guilds', [GuildController::class, 'create']);
    Route::post('/guilds/{guild}/join', [GuildController::class, 'join']);
    Route::post('/guilds/leave', [GuildController::class, 'leave']);
    Route::post('/guilds/contribute', [GuildController::class, 'contribute']);

    // Stock exchange.
    Route::get('/stocks', [StockController::class, 'index']);
    Route::post('/stocks/{listed}/buy', [StockController::class, 'buy']);
    Route::post('/stocks/{listed}/sell', [StockController::class, 'sell']);

    // Player exchange.
    Route::get('/exchange', [ExchangeController::class, 'index']);
    Route::post('/exchange', [ExchangeController::class, 'create']);
    Route::post('/exchange/{listing}/buy', [ExchangeController::class, 'buy']);
    Route::post('/exchange/{listing}/cancel', [ExchangeController::class, 'cancel']);
});
