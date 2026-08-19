<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Application\UseCases;

use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\ProviderRepository;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\SubscriptionRepository;
use PactTrackSDK\SharedResources\Modules\User\Application\Services\UserRegistration;
use PactTrackSDK\SharedResources\Modules\User\Domain\Services\SubdomainAllocator;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Role;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Subdomain;
use PactTrackSDK\SharedResources\Modules\User\Models\Provider;
use PactTrackSDK\SharedResources\Modules\Workspace\Application\Repository\Ports\WorkspaceRepository;
use PactTrackSDK\SharedResources\Modules\Workspace\Domain\ValueObjects\WorkspaceType;
use PactTrackSDK\SharedResources\SDK\Application\Ports\Transactional;

/**
 * Use case behind sign-up: turns a form submission into a tenant.
 *
 * This class knows *sequence* and nothing else. Every rule it depends on lives
 * somewhere it can be reused and tested on its own:
 *
 *   - what a valid, unique subdomain is  -> Domain\ValueObjects\Subdomain,
 *                                           Domain\Services\SubdomainAllocator
 *   - how a login is created and rolled  -> Application\Services\UserRegistration
 *   - how either is persisted            -> the repository ports
 *
 * What remains here is the part that genuinely belongs to signup: that these
 * steps happen together or not at all, and in this order.
 *
 * The order is forced by the schema rather than chosen: `providers.owner_user_id`
 * is a non-null FK to `users` while `users.provider_id` points back at
 * `providers`, so the user is created first and attachToProvider() closes the
 * loop. That cycle is exactly why the transaction is not decoration — a
 * half-built signup leaves a `users` row with no `provider_id`, which
 * authenticates fine but is denied by every TenantScopedPolicy. To the person
 * who just signed up that reads as "my account exists but nothing works".
 */
class RegisterProvider
{
    /**
     * Free trial length, in days.
     *
     * Borderline: arguably a billing policy rather than a registration detail.
     * It stays here only because nothing else reads it yet — move it to
     * config/pacttrack.php the moment a second caller (billing, marketing copy)
     * needs the same number, or the two will drift.
     */
    private const TRIAL_DAYS = 14;

    public function __construct(
        private readonly UserRegistration $registration,
        private readonly ProviderRepository $providers,
        private readonly SubscriptionRepository $subscriptions,
        private readonly WorkspaceRepository $workspaces,
        private readonly SubdomainAllocator $subdomains,
        private readonly Transactional $transaction,
    ) {
    }

    /**
     * @param  string|null  $subdomain  Omit to derive one from the business
     *                                  name. Pass a value only when the signup
     *                                  form lets the user choose it — an
     *                                  invalid choice throws, because at that
     *                                  point it is a mistake worth reporting
     *                                  rather than something to silently fix.
     *
     * @throws \RuntimeException         when the email is already registered
     * @throws \InvalidArgumentException when an explicitly chosen subdomain is
     *                                   malformed or reserved
     */
    public function handle(
        string $name,
        string $email,
        string $password,
        string $businessName,
        ?string $subdomain = null,
        string $plan = 'professional',
    ): Provider {
        // Resolved before the transaction opens: this is pure computation, and
        // a malformed explicit subdomain should fail without having touched the
        // database at all.
        $desired = $subdomain !== null
            ? Subdomain::fromString($subdomain)
            : Subdomain::fromLabel($businessName);

        return $this->transaction->run(function () use (
            $name,
            $email,
            $password,
            $businessName,
            $desired,
            $plan,
        ): Provider {
            $owner = $this->registration->register($name, $email, $password, Role::Owner);

            $trialEndsAt = now()->addDays(self::TRIAL_DAYS);

            $provider = $this->providers->create([
                'owner_user_id' => $owner->getKey(),
                'business_name' => $businessName,
                'subdomain' => $this->subdomains->allocate($desired)->value,
                // Denormalized cache of the Subscription row created below —
                // see Models\Subscription and .claude/rules/user.md. Kept in
                // sync here because this is the only place either changes today;
                // once Stripe webhooks can update a subscription mid-lifecycle,
                // that path must write both too.
                'plan' => $plan,
                'trial_ends_at' => $trialEndsAt,
            ]);

            $this->subscriptions->create([
                'provider_id' => $provider->getKey(),
                'plan' => $plan,
                'status' => 'trialing',
                'trial_ends_at' => $trialEndsAt,
            ]);

            $this->registration->attachToProvider($owner, (int) $provider->getKey());

            // Every provider needs at least one workspace the moment they sign
            // up: RequestWorkspaceContext's "provider's sole workspace"
            // fallback only ever resolves when exactly one exists, and until
            // it resolves, WorkspaceScope narrows nothing on Matter/Document/
            // Envelope/Client — not unsafe (provider_id is still the tenancy
            // barrier) but not scoped either, which is the gap that let the
            // clients list return every client regardless of workspace. Type
            // defaults to General since sign-up doesn't collect a
            // workspace_type yet; label columns are left blank so Workspace's
            // own creating() hook fills them from that type's preset.
            $this->workspaces->create([
                'provider_id' => $provider->getKey(),
                'owner_id' => $owner->getKey(),
                'name' => $businessName,
                'workspace_type' => WorkspaceType::General->value,
            ]);

            return $provider->setRelation('owner', $owner);
        });
    }
}
