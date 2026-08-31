<?php

namespace PactTrackSDK\SharedResources\Modules\Dashboard;

use Illuminate\Support\ServiceProvider;

class DashboardProvider extends ServiceProvider
{
    protected array $providers = [
        //
    ];

    public function register(): void
    {
        foreach ($this->providers as $provider) {
            $this->app->register($provider);
        }
    }

    public function boot(): void
    {
        //
    }
}
