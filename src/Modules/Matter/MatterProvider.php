<?php

namespace PactTraceSDK\SharedResources\Modules\Matter;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
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
    }

    public function boot(): void
    {
        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }
    }
}
