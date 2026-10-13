<?php

use App\Http\Controllers\Account\AccountPageController;
use App\Models\Account;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::view('/login', 'pages.auth.index')->middleware('guest')->name('login');

Route::middleware('auth')->group(function () {
    Route::view('/dashboard', 'pages.dashboard.index')->name('dashboard');
    Route::get('/settings', AccountPageController::class)
        ->can('viewAny', Account::class)
        ->name('settings.index');
});
