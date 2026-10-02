<?php

use App\Http\Controllers\Account\AccountController;
use App\Http\Controllers\Auth\AuthController;
use App\Models\Account;
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
});
