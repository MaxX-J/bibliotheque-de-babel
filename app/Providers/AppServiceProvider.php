<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Porte réseau et actions sans compte (A10). La réponse 429 au format du contrat
        // est produite par App\Exceptions\RenduDesErreurs.
        RateLimiter::for('reseau', fn (Request $request) => Limit::perMinute(config('bibliotheque.quota_par_minute'))
            ->by($request->ip()));
    }
}
