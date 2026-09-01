<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Tests;

use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Role;
use PactTrackSDK\SharedResources\Modules\User\Models\User;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * Coverage for UserPolicy::manageMembers — exercised through the real Gate
 * (`$user->can(...)`), the same way TenantIsolationTest checks its policies, so
 * an unregistered policy can't pass its own unit test while authorising nothing
 * in production.
 *
 * The gate is deliberately narrower than the `user.invite` / `user.update`
 * permission (which the Admin role also holds): it additionally requires the
 * actor to *be* the provider owner.
 */
class UserPolicyTest extends BaseTest
{
    private TestScenarioCollection $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = ProviderTenantScenario::make('user-policy');
    }

    public function test_the_owner_may_manage_members(): void
    {
        $this->assertTrue($this->tenant['owner']->can('manageMembers', User::class));
    }

    public function test_a_plain_staff_member_may_not(): void
    {
        $this->assertFalse($this->tenant['staff']->can('manageMembers', User::class));
    }

    public function test_an_admin_may_not_even_though_they_hold_the_permissions(): void
    {
        $admin = User::factory()->create(['provider_id' => $this->tenant['provider']->id]);
        $admin->assignRole(Role::Admin->value);

        $this->assertTrue($admin->hasPermissionTo('user.update'));
        $this->assertFalse($admin->can('manageMembers', User::class));
    }

    public function test_a_client_role_user_may_not(): void
    {
        $this->assertFalse($this->tenant['clientUser']->can('manageMembers', User::class));
    }

    public function test_an_owner_role_user_who_is_not_the_actual_account_owner_may_not(): void
    {
        // Owner *role* assigned, but they are not `providers.owner_user_id` —
        // the gate keys off the row, not the spatie role.
        $pretender = User::factory()->create(['provider_id' => $this->tenant['provider']->id]);
        $pretender->assignRole(Role::Owner->value);

        $this->assertFalse($pretender->can('manageMembers', User::class));
    }
}
