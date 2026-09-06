<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Tests;

use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\SanctumServiceProvider;
use PactTrackSDK\SharedResources\Modules\Matter\Models\Matter;
use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Role;
use PactTrackSDK\SharedResources\Modules\User\Models\User;
use PactTrackSDK\SharedResources\TestCase\Extras\LoadsModuleApiRoutes;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * HTTP coverage for the roster-mutating actions:
 *
 *   PATCH  /api/v1/team/members/{member}          — change a teammate's role
 *   DELETE /api/v1/team/members/{member}          — deactivate a teammate
 *   POST   /api/v1/team/members/{member}/restore  — restore a deactivated teammate
 *
 * Role change stays OWNER-ONLY (`manageMembers` — `providers.owner_user_id`,
 * not merely a permission): an Admin who can invite/resend still gets a 403.
 * Deactivate / Restore are wider (`changeMemberStatus`): the Owner on any
 * non-owner member, OR an Admin on a *Staff* member — a non-Staff target from
 * a non-owner is a 422 `reason: staff_only`. Plus the structural guardrails:
 * not yourself, not the owner, not another tenant.
 */
class TeamMemberManagementControllerTest extends BaseTest
{
    use LoadsModuleApiRoutes;

    private TestScenarioCollection $tenant;

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), SanctumServiceProvider::class];
    }

    protected function moduleApiRoutes(): array
    {
        return [__DIR__ . '/../routes/api.php'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = ProviderTenantScenario::make('team-manage');
    }

    private function admin(): User
    {
        $admin = User::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'email' => 'admin@team-manage.test',
        ]);
        $admin->assignRole(Role::Admin->value);

        return $admin;
    }

    private function extraStaff(string $email = 'extra-staff@team-manage.test'): User
    {
        $staff = User::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'email' => $email,
        ]);
        $staff->assignRole(Role::Staff->value);

        return $staff;
    }

    private function deactivatedStaff(string $email = 'gone-staff@team-manage.test'): User
    {
        $staff = $this->extraStaff($email);
        $staff->forceFill(['status' => 'deactivated', 'deactivated_at' => now()])->save();

        return $staff;
    }

    // ── Authentication / authorisation ────────────────────────────────────

    public function test_all_three_endpoints_require_authentication(): void
    {
        $staff = $this->tenant['staff'];

        $this->patchJson("/api/v1/team/members/{$staff->id}", ['role' => 'admin'])->assertStatus(401);
        $this->deleteJson("/api/v1/team/members/{$staff->id}")->assertStatus(401);
        $this->postJson("/api/v1/team/members/{$staff->id}/restore")->assertStatus(401);
    }

    public function test_a_plain_staff_member_is_forbidden_from_every_endpoint(): void
    {
        Sanctum::actingAs($this->tenant['staff']);
        $target = $this->extraStaff();

        $this->patchJson("/api/v1/team/members/{$target->id}", ['role' => 'admin'])->assertStatus(403);
        $this->deleteJson("/api/v1/team/members/{$target->id}")->assertStatus(403);
        $this->postJson("/api/v1/team/members/{$target->id}/restore")->assertStatus(403);
    }

    public function test_an_admin_is_still_forbidden_from_changing_a_role(): void
    {
        $admin = $this->admin();
        // Sanity: the Admin really does hold the invite/update/delete permissions.
        $this->assertTrue($admin->hasPermissionTo('user.invite'));
        $this->assertTrue($admin->hasPermissionTo('user.update'));
        $this->assertTrue($admin->hasPermissionTo('user.delete'));

        Sanctum::actingAs($admin);
        $target = $this->extraStaff();

        // Role change is owner-only regardless of the permission.
        $this->patchJson("/api/v1/team/members/{$target->id}", ['role' => 'staff'])->assertStatus(403);
        $this->assertTrue($target->fresh()->hasRole(Role::Staff->value));
    }

    public function test_an_admin_can_deactivate_and_restore_a_staff_member(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);
        $target = $this->extraStaff();

        $this->deleteJson("/api/v1/team/members/{$target->id}")->assertStatus(204);
        $this->assertSame('deactivated', $target->fresh()->status);

        $this->postJson("/api/v1/team/members/{$target->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.id', $target->id)
            ->assertJsonPath('data.status', 'active');
        $this->assertSame('active', $target->fresh()->status);
        $this->assertNull($target->fresh()->deactivated_at);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.deactivated',
            'auditable_id' => $target->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.reactivated',
            'auditable_id' => $target->id,
        ]);
    }

    public function test_an_admin_cannot_deactivate_or_restore_a_non_staff_member(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $otherAdmin = User::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'email' => 'other-admin@team-manage.test',
        ]);
        $otherAdmin->assignRole(Role::Admin->value);

        $this->deleteJson("/api/v1/team/members/{$otherAdmin->id}")
            ->assertStatus(422)
            ->assertJsonPath('reason', 'staff_only');
        $this->assertSame('active', $otherAdmin->fresh()->status);

        // The owner row from a non-owner caller — 'owner' wins over the staff check.
        $this->deleteJson("/api/v1/team/members/{$this->tenant['owner']->id}")
            ->assertStatus(422)
            ->assertJsonPath('reason', 'owner');

        $otherAdmin->forceFill(['status' => 'deactivated'])->save();
        $this->postJson("/api/v1/team/members/{$otherAdmin->id}/restore")
            ->assertStatus(422)
            ->assertJsonPath('reason', 'staff_only');
        $this->assertSame('deactivated', $otherAdmin->fresh()->status);
    }

    // ── The owner's happy paths ───────────────────────────────────────────

    public function test_the_owner_can_change_a_staff_members_role(): void
    {
        Sanctum::actingAs($this->tenant['owner']);
        $target = $this->extraStaff();

        $this->patchJson("/api/v1/team/members/{$target->id}", ['role' => 'admin'])
            ->assertOk()
            ->assertJsonPath('data.role', 'admin')
            ->assertJsonPath('data.id', $target->id);

        $target->refresh();
        $this->assertTrue($target->hasRole(Role::Admin->value));
        $this->assertFalse($target->hasRole(Role::Staff->value));

        $this->assertDatabaseHas('audit_logs', [
            'provider_id' => $this->tenant['provider']->id,
            'user_id' => $this->tenant['owner']->id,
            'action' => 'user.role_changed',
            'auditable_type' => User::class,
            'auditable_id' => $target->id,
        ]);
    }

    public function test_the_owner_can_remove_a_staff_member(): void
    {
        Sanctum::actingAs($this->tenant['owner']);
        $target = $this->extraStaff();

        // A matter assigned to the departing staffer must fall back to nobody.
        $matter = $this->tenant['matter'];
        $matter->forceFill(['assigned_staff_id' => $target->id])->save();

        $this->deleteJson("/api/v1/team/members/{$target->id}")->assertStatus(204);

        $target->refresh();
        $this->assertSame('deactivated', $target->status);
        $this->assertNotNull($target->deactivated_at);

        $this->assertNull(
            Matter::query()->acrossWorkspaces()->whereKey($matter->id)->value('assigned_staff_id'),
        );

        $this->assertDatabaseHas('audit_logs', [
            'provider_id' => $this->tenant['provider']->id,
            'user_id' => $this->tenant['owner']->id,
            'action' => 'user.deactivated',
            'auditable_id' => $target->id,
        ]);

        // Soft, not hard — the row (and its audit trail) survives.
        $this->assertDatabaseHas('users', ['id' => $target->id]);
    }

    public function test_the_owner_can_restore_a_deactivated_member(): void
    {
        Sanctum::actingAs($this->tenant['owner']);
        $target = $this->deactivatedStaff();

        $this->postJson("/api/v1/team/members/{$target->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.id', $target->id)
            ->assertJsonPath('data.status', 'active');

        $target->refresh();
        $this->assertSame('active', $target->status);
        $this->assertNull($target->deactivated_at);

        $this->assertDatabaseHas('audit_logs', [
            'provider_id' => $this->tenant['provider']->id,
            'user_id' => $this->tenant['owner']->id,
            'action' => 'user.reactivated',
            'auditable_id' => $target->id,
        ]);
    }

    // ── Guardrails ────────────────────────────────────────────────────────

    public function test_the_owner_cannot_restore_themselves(): void
    {
        $owner = $this->tenant['owner'];
        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/team/members/{$owner->id}/restore")
            ->assertStatus(422)
            ->assertJsonPath('reason', 'self');
    }

    public function test_the_owner_cannot_change_their_own_role(): void
    {
        $owner = $this->tenant['owner'];
        Sanctum::actingAs($owner);

        $this->patchJson("/api/v1/team/members/{$owner->id}", ['role' => 'staff'])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'self');

        $this->assertTrue($owner->fresh()->hasRole(Role::Owner->value));
    }

    public function test_the_owner_cannot_remove_themselves(): void
    {
        $owner = $this->tenant['owner'];
        Sanctum::actingAs($owner);

        $this->deleteJson("/api/v1/team/members/{$owner->id}")
            ->assertStatus(422)
            ->assertJsonPath('reason', 'self');

        $this->assertSame('active', $owner->fresh()->status);
    }

    public function test_the_owner_row_is_untouchable_by_a_non_owner_owner_role_caller(): void
    {
        // `providers.owner_user_id` is a single column — a provider has exactly
        // one owner — so "block anything that would leave zero owners" reduces
        // to "the owner row is untouchable here":
        //
        //  - PATCH (role change): `manageMembers` only passes for the real
        //    owner row, so an owner-*role* user who isn't `owner_user_id`
        //    can't reach it at all (403).
        //  - DELETE/restore: `changeMemberStatus` passes for any `user.delete`
        //    holder (this caller holds every permission), but the owner-row
        //    guard in TeamMembershipRules then rejects it → 422 `reason: owner`.
        $ownerRoleButNotTheRow = User::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'email' => 'not-really-owner@team-manage.test',
        ]);
        $ownerRoleButNotTheRow->assignRole(Role::Owner->value);
        Sanctum::actingAs($ownerRoleButNotTheRow);

        $owner = $this->tenant['owner'];

        $this->patchJson("/api/v1/team/members/{$owner->id}", ['role' => 'staff'])->assertStatus(403);
        $this->deleteJson("/api/v1/team/members/{$owner->id}")
            ->assertStatus(422)
            ->assertJsonPath('reason', 'owner');

        $this->assertTrue($owner->fresh()->hasRole(Role::Owner->value));
        $this->assertSame('active', $owner->fresh()->status);
    }

    public function test_another_tenants_member_is_a_404_on_every_endpoint(): void
    {
        $other = ProviderTenantScenario::make('team-manage-other');

        Sanctum::actingAs($this->tenant['owner']);

        $this->patchJson("/api/v1/team/members/{$other['staff']->id}", ['role' => 'admin'])
            ->assertStatus(404);
        $this->deleteJson("/api/v1/team/members/{$other['staff']->id}")
            ->assertStatus(404);
        $this->postJson("/api/v1/team/members/{$other['staff']->id}/restore")
            ->assertStatus(404);

        $this->assertTrue($other['staff']->fresh()->hasRole(Role::Staff->value));
        $this->assertSame('active', $other['staff']->fresh()->status);
    }

    // ── Role validation ──────────────────────────────────────────────────

    public function test_role_must_be_admin_or_staff(): void
    {
        Sanctum::actingAs($this->tenant['owner']);
        $target = $this->extraStaff();

        foreach (['owner', 'client', 'superuser', ''] as $bad) {
            $this->patchJson("/api/v1/team/members/{$target->id}", ['role' => $bad])
                ->assertStatus(422)
                ->assertJsonValidationErrors('role');
        }

        $this->patchJson("/api/v1/team/members/{$target->id}", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');

        $this->assertTrue($target->fresh()->hasRole(Role::Staff->value));
    }

    public function test_changing_a_role_to_its_current_value_is_a_no_op_without_an_audit_row(): void
    {
        Sanctum::actingAs($this->tenant['owner']);
        $target = $this->extraStaff();

        $this->patchJson("/api/v1/team/members/{$target->id}", ['role' => 'staff'])->assertOk();

        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'user.role_changed',
            'auditable_id' => $target->id,
        ]);
    }
}
