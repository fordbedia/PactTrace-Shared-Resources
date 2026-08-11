<?php

declare(strict_types=1);

namespace PactTraceSDK\SharedResources\Modules\User\Tests;

use PactTraceSDK\SharedResources\Modules\User\Models\User;
use PactTraceSDK\SharedResources\Modules\User\Domain\ValueObjects\Permission;
use PactTraceSDK\SharedResources\Modules\User\Domain\ValueObjects\Role;
use PactTraceSDK\SharedResources\TestCase\Migrations\BaseTest;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * The role catalogue itself, independent of any policy.
 *
 * The seeded catalogue ships inside the test snapshot, so these assertions also
 * serve as a staleness alarm: if the enums change and nobody regenerates the
 * dump, this is the test that fails first and says why.
 */
class RolePermissionTest extends BaseTest
{
    public function test_every_role_in_the_enum_is_seeded(): void
    {
        foreach (Role::values() as $role) {
            $this->assertTrue(
                SpatieRole::query()->where('name', $role)->exists(),
                "Role [{$role}] is missing from the database. Re-run RolePermissionSeeder and regenerate the test snapshot."
            );
        }
    }

    public function test_every_permission_in_the_enum_is_seeded(): void
    {
        $seeded = SpatiePermission::query()->pluck('name')->all();

        $this->assertEqualsCanonicalizing(
            Permission::values(),
            $seeded,
            'The seeded permissions have drifted from the Permission enum. Re-run RolePermissionSeeder and regenerate the test snapshot.'
        );
    }

    public function test_owner_holds_every_permission(): void
    {
        $owner = SpatieRole::query()->where('name', Role::Owner->value)->firstOrFail();

        $this->assertEqualsCanonicalizing(
            Permission::values(),
            $owner->permissions->pluck('name')->all()
        );
    }

    public function test_staff_cannot_administer_the_tenant(): void
    {
        $user = $this->userWithRole(Role::Staff);

        // Day-to-day work: yes.
        $this->assertTrue($user->hasPermissionTo(Permission::ClientCreate->value));
        $this->assertTrue($user->hasPermissionTo(Permission::MatterCreate->value));
        $this->assertTrue($user->hasPermissionTo(Permission::DocumentUpload->value));

        // Running the business: no.
        $this->assertFalse($user->hasPermissionTo(Permission::ProviderUpdate->value));
        $this->assertFalse($user->hasPermissionTo(Permission::ProviderManageBilling->value));
        $this->assertFalse($user->hasPermissionTo(Permission::ProviderManageBranding->value));
        $this->assertFalse($user->hasPermissionTo(Permission::UserInvite->value));
        $this->assertFalse($user->hasPermissionTo(Permission::ClientDelete->value));
    }

    public function test_client_can_participate_but_not_manage(): void
    {
        $user = $this->userWithRole(Role::Client);

        $this->assertTrue($user->hasPermissionTo(Permission::MatterView->value));
        $this->assertTrue($user->hasPermissionTo(Permission::DocumentUpload->value));
        $this->assertTrue($user->hasPermissionTo(Permission::EnvelopeSign->value));
        $this->assertTrue($user->hasPermissionTo(Permission::MessageSend->value));

        $this->assertFalse($user->hasPermissionTo(Permission::MatterCreate->value));
        $this->assertFalse($user->hasPermissionTo(Permission::MatterDelete->value));
        $this->assertFalse($user->hasPermissionTo(Permission::ClientCreate->value));
        $this->assertFalse($user->hasPermissionTo(Permission::EnvelopeCreate->value));
        $this->assertFalse($user->hasPermissionTo(Permission::AuditLogView->value));
    }

    public function test_primary_role_reflects_the_assigned_role(): void
    {
        $this->assertSame(Role::Owner, $this->userWithRole(Role::Owner)->primaryRole());
        $this->assertSame(Role::Staff, $this->userWithRole(Role::Staff)->primaryRole());
        $this->assertSame(Role::Client, $this->userWithRole(Role::Client)->primaryRole());
    }

    public function test_a_user_with_no_role_has_no_primary_role(): void
    {
        $user = User::factory()->create();

        $this->assertNull($user->primaryRole());
        $this->assertFalse($user->isProviderSide());
        $this->assertFalse($user->isClientUser());
    }

    public function test_the_most_privileged_role_wins_when_several_are_assigned(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::Client->value);
        $user->assignRole(Role::Owner->value);

        // Authorisation must never silently downgrade because of assignment order.
        $this->assertSame(Role::Owner, $user->fresh()->primaryRole());
        $this->assertTrue($user->fresh()->isProviderSide());
    }

    public function test_provider_side_classification(): void
    {
        $this->assertTrue($this->userWithRole(Role::Owner)->isProviderSide());
        $this->assertTrue($this->userWithRole(Role::Staff)->isProviderSide());
        $this->assertFalse($this->userWithRole(Role::Client)->isProviderSide());
        $this->assertTrue($this->userWithRole(Role::Client)->isClientUser());
    }

    private function userWithRole(Role $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user->fresh();
    }
}
