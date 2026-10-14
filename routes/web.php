<?php

use App\Http\Controllers\Account\AccountPageController;
use App\Http\Controllers\Performance\PerformancePageController;
use App\Models\Account;
use App\Models\Division;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/performance');

Route::view('/login', 'pages.auth.index')->middleware('guest')->name('login');

Route::middleware('auth')->group(function () {
    Route::get('/performance', PerformancePageController::class)->name('performance.index');
    Route::get('/settings', AccountPageController::class)
        ->can('viewAny', Account::class)
        ->name('settings.index');
    Route::view('/divisions', 'pages.settings.divisions')
        ->can('viewAny', Division::class)
        ->name('divisions.index');
});
