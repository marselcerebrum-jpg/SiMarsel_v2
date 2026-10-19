<?php

use App\Http\Controllers\Account\AccountPageController;
use App\Http\Controllers\Performance\PerformancePageController;
use App\Models\Account;
use App\Models\Division;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::view('/login', 'pages.auth.index')->middleware('guest')->name('login');

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', PerformancePageController::class)->name('dashboard');
    Route::view('/performance/products', 'pages.performance.products')->name('performance.products');
    Route::get('/settings', AccountPageController::class)
        ->can('viewAny', Account::class)
        ->name('settings.index');
    Route::view('/divisions', 'pages.settings.divisions')
        ->can('viewAny', Division::class)
        ->name('divisions.index');
});
