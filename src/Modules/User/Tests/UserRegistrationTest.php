<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Tests;

use PactTrackSDK\SharedResources\Modules\User\Application\Services\UserRegistration;
use PactTrackSDK\SharedResources\Modules\User\Application\UseCases\RegisterProvider;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Role;
use PactTrackSDK\SharedResources\Modules\User\Models\Provider;
use PactTrackSDK\SharedResources\Modules\User\Models\Subscription;
use PactTrackSDK\SharedResources\Modules\User\Models\User;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use RuntimeException;

/**
 * The account-lifecycle service, plus the signup use case that composes it.
 *
 * Both are covered here because the interesting property is how they divide the
 * work: UserRegistration owns everything about a login, RegisterProvider owns
 * only the ordering and atomicity. The rollback case is the one that matters
 * most — see the note on it below.
 */
class UserRegistrationTest extends BaseTest
{
    private UserRegistration $registration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registration = $this->app->make(UserRegistration::class);
    }

    public function test_it_creates_a_login_in_the_requested_role(): void
    {
        $user = $this->registration->register('Jane Doe', 'jane@example.test', 'secret-password', Role::Owner);

        $this->assertTrue($user->exists);
        $this->assertSame(Role::Owner, $user->primaryRole());
        $this->assertTrue($user->isProviderSide());
    }

    /**
     * The same service serves invitation acceptance, which needs the client
     * role — see .claude/rules/client.md.
     */
    public function test_it_can_create_a_client_side_login(): void
    {
        $user = $this->registration->register('John Smith', 'john@example.test', 'secret-password', Role::Client);

        $this->assertSame(Role::Client, $user->primaryRole());
        $this->assertTrue($user->isClientUser());
    }

    public function test_it_hashes_the_password_exactly_once(): void
    {
        $user = $this->registration->register('Jane Doe', 'jane@example.test', 'secret-password', Role::Owner);

        $this->assertNotSame('secret-password', $user->password);
        $this->assertTrue(password_verify('secret-password', $user->password));
    }

    public function test_it_normalizes_the_email(): void
    {
        $user = $this->registration->register('Jane Doe', '  Jane@Example.TEST  ', 'secret-password', Role::Owner);

        $this->assertSame('jane@example.test', $user->email);
    }

    /**
     * Without normalisation these would be two accounts nobody could tell apart
     * at the login form, because the UNIQUE index compares the raw string.
     */
    public function test_it_rejects_a_duplicate_email_regardless_of_casing(): void
    {
        $this->registration->register('Jane Doe', 'jane@example.test', 'secret-password', Role::Owner);

        $this->expectException(RuntimeException::class);

        $this->registration->register('Impostor', 'JANE@EXAMPLE.TEST', 'other-password', Role::Owner);
    }

    public function test_it_reports_email_availability_case_insensitively(): void
    {
        $this->assertTrue($this->registration->isEmailAvailable('jane@example.test'));

        $this->registration->register('Jane Doe', 'jane@example.test', 'secret-password', Role::Owner);

        $this->assertFalse($this->registration->isEmailAvailable('  JANE@Example.test '));
    }

    public function test_it_attaches_a_login_to_a_tenant(): void
    {
        $user = $this->registration->register('Jane Doe', 'jane@example.test', 'secret-password', Role::Owner);
        $provider = Provider::factory()->create(['owner_user_id' => $user->getKey()]);

        $this->registration->attachToProvider($user, (int) $provider->getKey());

        $this->assertSame((int) $provider->getKey(), (int) $user->fresh()->provider_id);
    }

    public function test_signup_creates_a_tenant_with_its_owner_wired_both_ways(): void
    {
        $provider = $this->app->make(RegisterProvider::class)
            ->handle('Jane Doe', 'jane@example.test', 'secret-password', 'Doe Law');

        $owner = $provider->owner;

        $this->assertSame('doe-law', $provider->subdomain);
        $this->assertSame('professional', $provider->plan); // no plan passed -> defaults to Professional
        $this->assertSame(Role::Owner, $owner->primaryRole());

        // Both directions of the FK cycle: provider -> owner, and owner -> tenant.
        $this->assertSame((int) $owner->getKey(), (int) $provider->owner_user_id);
        $this->assertSame((int) $provider->getKey(), (int) $owner->fresh()->provider_id);

        // The owner is dropped into the workspace created alongside the tenant
        // on their next sign-in — see UserAuthentication::syncWorkspaceSession().
        $workspace = \PactTrackSDK\SharedResources\Modules\Workspace\Models\Workspace::query()
            ->where('provider_id', $provider->getKey())
            ->sole();
        $this->assertSame((int) $workspace->getKey(), (int) $owner->fresh()->default_workspace_id);

        // The tenant's first workspace is the primary one — it can never be
        // deactivated. RegisterProvider is the only place this flag is set.
        $this->assertTrue($workspace->is_primary);

        // The Subscription row is the authoritative billing record; provider.plan
        // above is only its denormalized cache — assert both agree.
        $subscription = Subscription::where('provider_id', $provider->getKey())->sole();
        $this->assertSame('professional', $subscription->plan);
        $this->assertSame('trialing', $subscription->status);
        $this->assertNotNull($subscription->trial_ends_at);
        $this->assertTrue($subscription->trial_ends_at->isFuture());
    }

    public function test_signup_honors_an_explicitly_chosen_plan(): void
    {
        $provider = $this->app->make(RegisterProvider::class)
            ->handle('Jane Doe', 'jane@example.test', 'secret-password', 'Doe Law', plan: 'starter');

        $this->assertSame('starter', $provider->plan);
        $this->assertSame('starter', Subscription::where('provider_id', $provider->getKey())->sole()->plan);
    }

    public function test_signup_walks_past_a_taken_subdomain(): void
    {
        $useCase = $this->app->make(RegisterProvider::class);

        $useCase->handle('Jane Doe', 'jane@example.test', 'secret-password', 'Doe Law');
        $second = $useCase->handle('John Roe', 'john@example.test', 'secret-password', 'Doe Law');

        $this->assertSame('doe-law-2', $second->subdomain);
    }

    public function test_signup_rejects_a_reserved_explicit_subdomain(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->app->make(RegisterProvider::class)
            ->handle('Jane Doe', 'jane@example.test', 'secret-password', 'Doe Law', subdomain: 'www');
    }

    /**
     * The reason the use case owns a transaction at all.
     *
     * A user row that survives a failed signup can authenticate but has no
     * provider_id, so every TenantScopedPolicy denies it — an account that
     * exists and does nothing, which is worse than no account.
     */
    public function test_a_failed_signup_leaves_no_orphaned_login(): void
    {
        $usersBefore = User::count();

        try {
            $this->app->make(RegisterProvider::class)->handle(
                'Jane Doe',
                'jane@example.test',
                'secret-password',
                'Doe Law',
                plan: 'not-a-real-plan',
            );
            $this->fail('Expected the invalid plan to abort registration.');
        } catch (\Throwable) {
            // The failure itself is not the point; what it leaves behind is.
        }

        $this->assertSame($usersBefore, User::count());
        $this->assertNull(User::query()->where('email', 'jane@example.test')->first());
    }
}
