<?php

namespace PactTraceSDK\SharedResources\Modules\Client;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use PactTraceSDK\SharedResources\Modules\Client\Models\Client;
use PactTraceSDK\SharedResources\Modules\Client\Policies\ClientPolicy;

class ClientProvider extends ServiceProvider
{
    protected array $providers = [
        //
    ];

    protected array $policies = [
        Client::class => ClientPolicy::class,
    ];

    public function register(): void
    {
        foreach ($this->providers as $provider) {
            $this->app->register($provider);
        }
    }

    public function boot(): void
    {
        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }
    }
}
