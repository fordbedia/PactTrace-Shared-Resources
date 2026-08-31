<?php

namespace PactTrackSDK\SharedResources\Modules\User;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use PactTrackSDK\SharedResources\Modules\User\Console\Commands\NotifyTrialEnding;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\ProviderRepository;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\SubscriptionRepository;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\TeamInvitationRepository;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\UserRepository;
use PactTrackSDK\SharedResources\Modules\User\Domain\Ports\AccessTokenIssuer;
use PactTrackSDK\SharedResources\Modules\User\Domain\Ports\SubdomainAvailability;
use PactTrackSDK\SharedResources\Modules\User\Infrastructure\Auth\SanctumTokenIssuer;
use PactTrackSDK\SharedResources\Modules\User\Infrastructure\Repositories\Eloquent\EloquentProviderRepository;
use PactTrackSDK\SharedResources\Modules\User\Infrastructure\Repositories\Eloquent\EloquentSubscriptionRepository;
use PactTrackSDK\SharedResources\Modules\User\Infrastructure\Repositories\Eloquent\EloquentTeamInvitationRepository;
use PactTrackSDK\SharedResources\Modules\User\Infrastructure\Repositories\Eloquent\EloquentUserRepository;
use PactTrackSDK\SharedResources\Modules\User\Models\Provider;
use PactTrackSDK\SharedResources\Modules\User\Models\User;
use PactTrackSDK\SharedResources\Modules\User\Policies\ProviderPolicy;
use PactTrackSDK\SharedResources\Modules\User\Policies\UserPolicy;

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
        User::class => UserPolicy::class,
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
        TeamInvitationRepository::class => EloquentTeamInvitationRepository::class,
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

        // Per acting-user + invitation resend limiter (route:
        // POST team/invitations/{invitation}/resend). Keyed so one admin
        // hammering one pending invite is what gets throttled, not resends
        // across the whole tenant. The app has no other rate-limit
        // convention to match — login/register are unthrottled today.
        RateLimiter::for('team-invitation-resend', function (Request $request): Limit {
            $invitation = $request->route('invitation');
            $invitationKey = is_object($invitation) ? $invitation->getKey() : $invitation;

            return Limit::perMinute(2)->by(
                ($request->user()?->getAuthIdentifier() ?? $request->ip()) . '|' . $invitationKey
            );
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                NotifyTrialEnding::class,
            ]);
        }
    }
}
