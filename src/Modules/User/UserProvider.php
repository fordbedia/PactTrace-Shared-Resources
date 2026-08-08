<?php

namespace PactTraceSDK\SharedResources\Modules\User;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use PactTraceSDK\SharedResources\Modules\User\Models\Provider;
use PactTraceSDK\SharedResources\Modules\User\Policies\ProviderPolicy;

class UserProvider extends ServiceProvider
{
    protected array $providers = [
        //
    ];

    /**
     * Models live in module namespaces, so Laravel's convention-based policy
     * discovery (App\Models\Foo -> App\Policies\FooPolicy) never finds them.
     * Every module registers its own policies explicitly.
     */
    protected array $policies = [
        Provider::class => ProviderPolicy::class,
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
