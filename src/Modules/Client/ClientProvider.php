<?php

namespace PactTraceSDK\SharedResources\Modules\Client;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use PactTraceSDK\SharedResources\Modules\Client\Application\Ports\Repository\ClientRepository;
use PactTraceSDK\SharedResources\Modules\Client\Application\Ports\Service\ClientListingService;
use PactTraceSDK\SharedResources\Modules\Client\Infrastructure\Repositories\Eloquent\EloquentClientRepository;
use PactTraceSDK\SharedResources\Modules\Client\Infrastructure\Services\ClientListingService as EloquentClientListingService;
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

		$this->app->singleton(ClientRepository::class, EloquentClientRepository::class);
		$this->app->singleton(ClientListingService::class, EloquentClientListingService::class);
    }

    public function boot(): void
    {
        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }
    }
}
