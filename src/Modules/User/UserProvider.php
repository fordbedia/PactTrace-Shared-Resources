<?php

namespace PactTrackSDK\SharedResources\Modules\User;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\AccountDeletionSignalReader;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\DepartingStaffReassignment;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\PlanUsageReader;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\ProviderInvitationCanceller;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\ProviderRepository;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\StripeWebhookEventRepository;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\SubscriptionRepository;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\TeamInvitationRepository;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\UserRepository;
use PactTrackSDK\SharedResources\Modules\User\Console\Commands\NotifyTrialEnding;
use PactTrackSDK\SharedResources\Modules\User\Console\Commands\SyncStripePortalConfigurations;
use PactTrackSDK\SharedResources\Modules\User\Domain\Ports\AccessTokenIssuer;
use PactTrackSDK\SharedResources\Modules\User\Domain\Ports\AvatarStorage;
use PactTrackSDK\SharedResources\Modules\User\Domain\Ports\BillingPortalConfigurator;
use PactTrackSDK\SharedResources\Modules\User\Domain\Ports\BillingProvider;
use PactTrackSDK\SharedResources\Modules\User\Domain\Ports\ProviderLogoStorage;
use PactTrackSDK\SharedResources\Modules\User\Domain\Ports\StripePriceCatalog;
use PactTrackSDK\SharedResources\Modules\User\Domain\Ports\SubdomainAvailability;
use PactTrackSDK\SharedResources\Modules\User\Infrastructure\Auth\SanctumTokenIssuer;
use PactTrackSDK\SharedResources\Modules\User\Infrastructure\Repositories\Eloquent\EloquentAccountDeletionSignals;
use PactTrackSDK\SharedResources\Modules\User\Infrastructure\Repositories\Eloquent\EloquentDepartingStaffReassignment;
use PactTrackSDK\SharedResources\Modules\User\Infrastructure\Repositories\Eloquent\EloquentPlanUsageReader;
use PactTrackSDK\SharedResources\Modules\User\Infrastructure\Repositories\Eloquent\EloquentProviderInvitationCanceller;
use PactTrackSDK\SharedResources\Modules\User\Infrastructure\Repositories\Eloquent\EloquentProviderRepository;
use PactTrackSDK\SharedResources\Modules\User\Infrastructure\Repositories\Eloquent\EloquentStripeWebhookEventRepository;
use PactTrackSDK\SharedResources\Modules\User\Infrastructure\Repositories\Eloquent\EloquentSubscriptionRepository;
use PactTrackSDK\SharedResources\Modules\User\Infrastructure\Repositories\Eloquent\EloquentTeamInvitationRepository;
use PactTrackSDK\SharedResources\Modules\User\Infrastructure\Repositories\Eloquent\EloquentUserRepository;
use PactTrackSDK\SharedResources\Modules\User\Infrastructure\Service\PublicDiskAvatarStorage;
use PactTrackSDK\SharedResources\Modules\User\Infrastructure\Service\PublicDiskProviderLogoStorage;
use PactTrackSDK\SharedResources\Modules\User\Infrastructure\Stripe\ConfigStripePriceCatalog;
use PactTrackSDK\SharedResources\Modules\User\Infrastructure\Stripe\StripeBillingPortalConfigurator;
use PactTrackSDK\SharedResources\Modules\User\Infrastructure\Stripe\StripeBillingProvider;
use PactTrackSDK\SharedResources\Modules\User\Models\Provider;
use PactTrackSDK\SharedResources\Modules\User\Models\User;
use PactTrackSDK\SharedResources\Modules\User\Policies\ProviderPolicy;
use PactTrackSDK\SharedResources\Modules\User\Policies\UserPolicy;
use Stripe\StripeClient;

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
        DepartingStaffReassignment::class => EloquentDepartingStaffReassignment::class,
        AccountDeletionSignalReader::class => EloquentAccountDeletionSignals::class,
        ProviderInvitationCanceller::class => EloquentProviderInvitationCanceller::class,
        TeamInvitationRepository::class => EloquentTeamInvitationRepository::class,
        ProviderRepository::class => EloquentProviderRepository::class,
        SubdomainAvailability::class => EloquentProviderRepository::class,
        SubscriptionRepository::class => EloquentSubscriptionRepository::class,
        AccessTokenIssuer::class => SanctumTokenIssuer::class,
        PlanUsageReader::class => EloquentPlanUsageReader::class,
        StripeWebhookEventRepository::class => EloquentStripeWebhookEventRepository::class,
    ];

    public function register(): void
    {
        foreach ($this->providers as $provider) {
            $this->app->register($provider);
        }

        foreach ($this->ports as $port => $adapter) {
            $this->app->bind($port, $adapter);
        }

        // Not in $ports: the adapter needs the disk name, chosen once by
        // config/env (same pattern as Document's `document_disk`), not a
        // per-user value. Defaults to the app's existing public disk.
        $this->app->bind(AvatarStorage::class, fn ($app) => new PublicDiskAvatarStorage(
            disk: $app['config']->get('filesystems.avatar_disk', 'public'),
        ));

        // Same shape as AvatarStorage above — the disk is chosen once by
        // config/env, not stored per row (providers.disk records which disk a
        // given logo_path was written to, for a future S3 move).
        $this->app->bind(ProviderLogoStorage::class, fn ($app) => new PublicDiskProviderLogoStorage(
            disk: $app['config']->get('filesystems.provider_logo_disk', 'public'),
        ));

        // Reads config/env once at resolution time rather than per call —
        // same reasoning as the two disks above. Swappable per-plan/interval
        // matrix, not a switch statement scattered across call sites; see
        // Domain\Ports\StripePriceCatalog.
        $this->app->bind(StripePriceCatalog::class, fn ($app) => new ConfigStripePriceCatalog(
            prices: (array) $app['config']->get('services.stripe.prices', []),
        ));

        // The real Stripe SDK client, scoped to this one binding rather than
        // process-wide global state (\Stripe::setApiKey()). Tests rebind
        // BillingProvider directly to Infrastructure\Stripe\FakeBillingProvider
        // (see .claude/rules/signature.md's FakeSignatureProvider precedent)
        // rather than relying on this binding at all.
        $this->app->bind(BillingProvider::class, fn ($app) => new StripeBillingProvider(
            client: new StripeClient((string) $app['config']->get('services.stripe.secret')),
        ));

        // Provisioning-only seam (the `stripe:sync-portal-configs` command),
        // separate from BillingProvider on purpose — nothing in the request
        // path touches it. Tests rebind to FakeBillingPortalConfigurator.
        $this->app->bind(BillingPortalConfigurator::class, fn ($app) => new StripeBillingPortalConfigurator(
            client: new StripeClient((string) $app['config']->get('services.stripe.secret')),
        ));
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
                ($request->user()?->getAuthIdentifier() ?? $request->ip()).'|'.$invitationKey
            );
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                NotifyTrialEnding::class,
                SyncStripePortalConfigurations::class,
            ]);
        }
    }
}
