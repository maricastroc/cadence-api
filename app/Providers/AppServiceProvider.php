<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    #[\Override]
    public function register(): void
    {
        if ($this->app->environment('local')) {
            $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Use environment() (reads cached config) rather than env(), which
        // returns null once config is cached and isn't reloaded per request
        // under a long-lived Octane worker.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
