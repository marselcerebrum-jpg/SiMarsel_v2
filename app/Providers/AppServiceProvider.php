<?php

namespace App\Providers;

use App\Http\Resources\AccountResource;
use App\Repositories\DashboardSnapshotCoverageRepository;
use App\Support\Period\PeriodCoverageSource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PeriodCoverageSource::class, DashboardSnapshotCoverageRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        View::composer('layouts.app', function ($view) {
            $account = Auth::user()?->loadMissing(['role', 'division']);

            $view->with('currentUser', $account ? AccountResource::make($account)->resolve() : null);
        });
    }
}
