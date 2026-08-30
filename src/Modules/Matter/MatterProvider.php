<?php

namespace PactTrackSDK\SharedResources\Modules\Matter;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use PactTrackSDK\SharedResources\Modules\Matter\Application\Ports\Query\AssignableMatterStaff;
use PactTrackSDK\SharedResources\Modules\Matter\Application\Ports\Repository\MattersRepository;
use PactTrackSDK\SharedResources\Modules\Matter\Application\Ports\Service\MatterStatsService;
use PactTrackSDK\SharedResources\Modules\Matter\Application\Ports\Service\MattersListingService;
use PactTrackSDK\SharedResources\Modules\Matter\Infrastructure\Queries\EloquentAssignableMatterStaff;
use PactTrackSDK\SharedResources\Modules\Matter\Infrastructure\Repositories\Eloquent\EloquentMattersRepository;
use PactTrackSDK\SharedResources\Modules\Matter\Infrastructure\Services\MatterStatsService as EloquentMatterStatsService;
use PactTrackSDK\SharedResources\Modules\Matter\Infrastructure\Services\MattersListingService as EloquentMattersListingService;
use PactTrackSDK\SharedResources\Modules\Matter\Models\Milestone;
use PactTrackSDK\SharedResources\Modules\Matter\Models\Matter;
use PactTrackSDK\SharedResources\Modules\Matter\Policies\MilestonePolicy;
use PactTrackSDK\SharedResources\Modules\Matter\Policies\MatterPolicy;

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
		$this->app->singleton(MatterStatsService::class, EloquentMatterStatsService::class);
		$this->app->singleton(AssignableMatterStaff::class, EloquentAssignableMatterStaff::class);
    }

    public function boot(): void
    {
        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }
    }
}
