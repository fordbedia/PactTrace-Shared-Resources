<?php

namespace PactTraceSDK\SharedResources\Modules\User;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use PactTraceSDK\SharedResources\Modules\User\Application\Repository\Ports\ProviderRepository;
use PactTraceSDK\SharedResources\Modules\User\Application\Repository\Ports\SubscriptionRepository;
use PactTraceSDK\SharedResources\Modules\User\Application\Repository\Ports\UserRepository;
use PactTraceSDK\SharedResources\Modules\User\Domain\Ports\AccessTokenIssuer;
use PactTraceSDK\SharedResources\Modules\User\Domain\Ports\SubdomainAvailability;
use PactTraceSDK\SharedResources\Modules\User\Infrastructure\Auth\SanctumTokenIssuer;
use PactTraceSDK\SharedResources\Modules\User\Infrastructure\Repositories\Eloquent\EloquentProviderRepository;
use PactTraceSDK\SharedResources\Modules\User\Infrastructure\Repositories\Eloquent\EloquentSubscriptionRepository;
use PactTraceSDK\SharedResources\Modules\User\Infrastructure\Repositories\Eloquent\EloquentUserRepository;
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

    /**
     * Ports -> the adapters that implement them. Application and domain code
     * type-hints only the left-hand side, so any of these can be faked in a
     * test, or swapped wholesale, by rebinding here.
     *
     * SubdomainAvailability and ProviderRepository intentionally resolve to the
     * same class: one table, two views of it, and callers depend on the narrower
     * one where that is all they need.
     *
     * Deliberately NOT named `$bindings`: Laravel reads a provider's `$bindings`
     * property itself (Application::register(), framework Application.php:908)
     * and would both double-register these and fatal on the protected
     * visibility — `foreach ($provider->bindings ...)` runs from outside the
     * class. Same trap applies to `$singletons`.
     *
     * @var array<class-string, class-string>
     */
    protected array $ports = [
        UserRepository::class => EloquentUserRepository::class,
        ProviderRepository::class => EloquentProviderRepository::class,
        SubdomainAvailability::class => EloquentProviderRepository::class,
        SubscriptionRepository::class => EloquentSubscriptionRepository::class,
        AccessTokenIssuer::class => SanctumTokenIssuer::class,
    ];

    public function register(): void
    {
        foreach ($this->providers as $provider) {
            $this->app->register($provider);
        }

        foreach ($this->ports as $port => $adapter) {
            $this->app->bind($port, $adapter);
        }
    }

    public function boot(): void
    {
        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }
    }
}
