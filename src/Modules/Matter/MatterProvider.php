<?php

namespace PactTraceSDK\SharedResources\Modules\Matter;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use PactTraceSDK\SharedResources\Modules\Matter\Application\Ports\Repository\MattersRepository;
use PactTraceSDK\SharedResources\Modules\Matter\Application\Ports\Service\MattersListingService;
use PactTraceSDK\SharedResources\Modules\Matter\Infrastructure\Repositories\Eloquent\EloquentMattersRepository;
use PactTraceSDK\SharedResources\Modules\Matter\Infrastructure\Services\MattersListingService as EloquentMattersListingService;
use PactTraceSDK\SharedResources\Modules\Matter\Models\Milestone;
use PactTraceSDK\SharedResources\Modules\Matter\Models\Matter;
use PactTraceSDK\SharedResources\Modules\Matter\Policies\MilestonePolicy;
use PactTraceSDK\SharedResources\Modules\Matter\Policies\MatterPolicy;

class MatterProvider extends ServiceProvider
{
    protected array $providers = [
        //
    ];

    protected array $policies = [
        Matter::class => MatterPolicy::class,
        Milestone::class => MilestonePolicy::class,
    ];

    public function register(): void
    {
        foreach ($this->providers as $provider) {
            $this->app->register($provider);
        }

		$this->app->singleton(MattersRepository::class, EloquentMattersRepository::class);
		$this->app->singleton(MattersListingService::class, EloquentMattersListingService::class);
    }

    public function boot(): void
    {
        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }
    }
}
