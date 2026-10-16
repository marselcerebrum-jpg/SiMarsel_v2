<?php

use App\Http\Controllers\Account\AccountController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Division\DivisionController;
use App\Http\Controllers\Performance\PackageDetailController;
use App\Http\Controllers\Performance\PaymentMethodDistributionController;
use App\Http\Controllers\Performance\PerformanceExportController;
use App\Http\Controllers\Performance\PerformanceSummaryController;
use App\Http\Controllers\Performance\PerformanceTrendController;
use App\Http\Controllers\Performance\PlatformContributionController;
use App\Http\Controllers\Performance\ProductPerformanceController;
use App\Http\Controllers\Performance\ProductPerformanceFilterController;
use App\Http\Controllers\Performance\PurchaseTimeDistributionController;
use App\Http\Controllers\Performance\TopPackageController;
use App\Models\Account;
use App\Models\Division;
use Illuminate\Support\Facades\Route;

Route::get('/csrf-cookie', fn () => response()->noContent())->name('api.csrf-cookie');

Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:5,1')
    ->name('api.login');

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('api.logout');

    Route::prefix('accounts')->name('api.accounts.')->group(function () {
        Route::get('/', [AccountController::class, 'index'])
            ->can('viewAny', Account::class)
            ->name('index');
        Route::post('/', [AccountController::class, 'store'])
            ->can('create', Account::class)
            ->name('store');
        Route::match(['put', 'patch'], '/{account}', [AccountController::class, 'update'])
            ->can('update', 'account')
            ->name('update');
        Route::delete('/{account}', [AccountController::class, 'destroy'])
            ->can('delete', 'account')
            ->name('destroy');
    });

    Route::prefix('divisions')->name('api.divisions.')->group(function () {
        Route::get('/', [DivisionController::class, 'index'])
            ->can('viewAny', Division::class)
            ->name('index');
        Route::post('/', [DivisionController::class, 'store'])
            ->can('create', Division::class)
            ->name('store');
        Route::get('/{division}', [DivisionController::class, 'show'])
            ->can('view', 'division')
            ->name('show');
        Route::match(['put', 'patch'], '/{division}', [DivisionController::class, 'update'])
            ->can('update', 'division')
            ->name('update');
        Route::delete('/{division}', [DivisionController::class, 'destroy'])
            ->can('delete', 'division')
            ->name('destroy');
    });

    Route::get('/performance/summary', PerformanceSummaryController::class)
        ->name('api.performance.summary');
    Route::get('/performance/trend', PerformanceTrendController::class)
        ->name('api.performance.trend');
    Route::get('/performance/payment-methods', PaymentMethodDistributionController::class)
        ->name('api.performance.payment-methods');
    Route::get('/performance/platform-contribution', PlatformContributionController::class)
        ->name('api.performance.platform-contribution');
    Route::get('/performance/purchase-times', PurchaseTimeDistributionController::class)
        ->name('api.performance.purchase-times');
    Route::get('/performance/export', PerformanceExportController::class)
        ->name('api.performance.export');
    Route::get('/performance/products', ProductPerformanceController::class)
        ->name('api.performance.products');
    Route::get('/performance/products/filters', ProductPerformanceFilterController::class)
        ->name('api.performance.products.filters');
    Route::get('/performance/packages', [PackageDetailController::class, 'index'])
        ->name('api.performance.packages.index');
    Route::get('/performance/packages/export', [PackageDetailController::class, 'export'])
        ->name('api.performance.packages.export');
    Route::get('/performance/top-packages', TopPackageController::class)
        ->name('api.performance.top-packages');
});
