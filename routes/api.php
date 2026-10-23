<?php

use App\Http\Controllers\Account\AccountController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Division\DivisionController;
use App\Http\Controllers\Performance\PaymentMethodDistributionController;
use App\Http\Controllers\Performance\PerformanceExportController;
use App\Http\Controllers\Performance\PerformanceSummaryController;
use App\Http\Controllers\Performance\PerformanceTrendController;
use App\Http\Controllers\Performance\PlatformContributionController;
use App\Http\Controllers\Performance\PurchaseTimeDistributionController;
use App\Http\Controllers\Platform\PlatformController;
use App\Http\Controllers\Product\ProductOverviewController;
use App\Http\Controllers\Promo\FunnelController;
use App\Http\Controllers\Promo\PromoAnalysisController;
use App\Http\Controllers\Promo\PromoCodeController;
use App\Http\Controllers\Promo\PromoSourceController;
use App\Http\Controllers\Promo\UnregisteredPromoCodeController;
use App\Http\Controllers\Task\TaskController;
use App\Models\Account;
use App\Models\Division;
use App\Models\Funnel;
use App\Models\PromoCode;
use App\Models\PromoSource;
use App\Models\Task;
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

    Route::get('/platforms', [PlatformController::class, 'index'])->name('api.platforms.index');

    Route::prefix('funnels')->name('api.funnels.')->group(function () {
        Route::get('/', [FunnelController::class, 'index'])
            ->can('viewAny', Funnel::class)
            ->name('index');
        Route::post('/', [FunnelController::class, 'store'])
            ->can('create', Funnel::class)
            ->name('store');
        Route::get('/{funnel}', [FunnelController::class, 'show'])
            ->can('view', 'funnel')
            ->name('show');
        Route::match(['put', 'patch'], '/{funnel}', [FunnelController::class, 'update'])
            ->can('update', 'funnel')
            ->name('update');
        Route::delete('/{funnel}', [FunnelController::class, 'destroy'])
            ->can('delete', 'funnel')
            ->name('destroy');
    });

    Route::prefix('promo-sources')->name('api.promo-sources.')->group(function () {
        Route::get('/', [PromoSourceController::class, 'index'])
            ->can('viewAny', PromoSource::class)
            ->name('index');
        Route::post('/', [PromoSourceController::class, 'store'])
            ->can('create', PromoSource::class)
            ->name('store');
        Route::get('/{promoSource}', [PromoSourceController::class, 'show'])
            ->can('view', 'promoSource')
            ->name('show');
        Route::match(['put', 'patch'], '/{promoSource}', [PromoSourceController::class, 'update'])
            ->can('update', 'promoSource')
            ->name('update');
        Route::delete('/{promoSource}', [PromoSourceController::class, 'destroy'])
            ->can('delete', 'promoSource')
            ->name('destroy');
    });

    Route::prefix('promo-codes')->name('api.promo-codes.')->group(function () {
        Route::get('/', [PromoCodeController::class, 'index'])
            ->can('viewAny', PromoCode::class)
            ->name('index');
        // Before /{promoCode}, so "unregistered" is not read as a promo code id.
        Route::get('/unregistered', [UnregisteredPromoCodeController::class, 'index'])
            ->can('viewAny', PromoCode::class)
            ->name('unregistered');
        Route::post('/', [PromoCodeController::class, 'store'])
            ->can('create', PromoCode::class)
            ->name('store');
        Route::post('/bulk', [PromoCodeController::class, 'bulkStore'])
            ->can('create', PromoCode::class)
            ->name('bulk-store');
        Route::get('/{promoCode}', [PromoCodeController::class, 'show'])
            ->can('view', 'promoCode')
            ->name('show');
        Route::match(['put', 'patch'], '/{promoCode}', [PromoCodeController::class, 'update'])
            ->can('update', 'promoCode')
            ->name('update');
        Route::delete('/{promoCode}', [PromoCodeController::class, 'destroy'])
            ->can('delete', 'promoCode')
            ->name('destroy');
    });

    Route::prefix('promo-analysis')->name('api.promo-analysis.')->group(function () {
        Route::get('/filters', [PromoAnalysisController::class, 'filters'])->name('filters');
        Route::get('/summary', [PromoAnalysisController::class, 'summary'])->name('summary');
        Route::get('/funnels', [PromoAnalysisController::class, 'funnels'])->name('funnels');
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
    Route::prefix('products')->name('api.products.')->group(function () {
        Route::get('/filters', [ProductOverviewController::class, 'filters'])->name('filters');
        Route::get('/top-packages/revenue', [ProductOverviewController::class, 'topByRevenue'])->name('top-packages.revenue');
        Route::get('/top-packages/volume', [ProductOverviewController::class, 'topByVolume'])->name('top-packages.volume');
        Route::get('/performance', [ProductOverviewController::class, 'performance'])->name('performance');
    });

    Route::prefix('tasks')->name('api.tasks.')->group(function () {
        Route::get('/meta', [TaskController::class, 'meta'])->name('meta');
        Route::get('/', [TaskController::class, 'index'])
            ->can('viewAny', Task::class)
            ->name('index');
        Route::get('/create-options', [TaskController::class, 'createOptions'])
            ->can('create', Task::class)
            ->name('create-options');
        Route::post('/', [TaskController::class, 'store'])
            ->can('create', Task::class)
            ->name('store');
        Route::get('/{task}', [TaskController::class, 'show'])
            ->whereNumber('task')
            ->can('view', 'task')
            ->name('show');
    });
});
