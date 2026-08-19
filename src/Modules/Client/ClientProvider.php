<?php

namespace PactTrackSDK\SharedResources\Modules\Client;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use PactTrackSDK\SharedResources\Modules\Client\Application\Ports\Repository\ClientInvitationRepository;
use PactTrackSDK\SharedResources\Modules\Client\Application\Ports\Repository\ClientRepository;
use PactTrackSDK\SharedResources\Modules\Client\Application\Ports\Service\ClientListingService;
use PactTrackSDK\SharedResources\Modules\Client\Infrastructure\Repositories\Eloquent\EloquentClientInvitationRepository;
use PactTrackSDK\SharedResources\Modules\Client\Infrastructure\Repositories\Eloquent\EloquentClientRepository;
use PactTrackSDK\SharedResources\Modules\Client\Infrastructure\Services\ClientListingService as EloquentClientListingService;
use PactTrackSDK\SharedResources\Modules\Client\Models\Client;
use PactTrackSDK\SharedResources\Modules\Client\Policies\ClientPolicy;

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
		$this->app->singleton(ClientInvitationRepository::class, EloquentClientInvitationRepository::class);
		$this->app->singleton(ClientListingService::class, EloquentClientListingService::class);
    }

    public function boot(): void
    {
        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }
    }
}
