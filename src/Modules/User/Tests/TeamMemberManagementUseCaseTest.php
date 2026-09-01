<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Tests;

use PactTrackSDK\SharedResources\Modules\Matter\Models\Matter;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\DepartingStaffReassignment;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\UserRepository;
use PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Team\ChangeTeamMemberRole;
use PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Team\DeactivateTeamMember;
use PactTrackSDK\SharedResources\Modules\User\Domain\Exceptions\CannotModifyTeamMemberException;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Role;
use PactTrackSDK\SharedResources\Modules\User\Models\User;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * Application-layer coverage for the two roster use cases, called directly
 * (not through the controller) so the domain guards and the repository/port
 * effects are proven to hold regardless of the HTTP adapter.
 */
class TeamMemberManagementUseCaseTest extends BaseTest
{
    private TestScenarioCollection $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = ProviderTenantScenario::make('team-uc');
    }

    private function changeRole(): ChangeTeamMemberRole
    {
        return $this->app->make(ChangeTeamMemberRole::class);
    }

    private function deactivate(): DeactivateTeamMember
    {
        return $this->app->make(DeactivateTeamMember::class);
    }

    private function staff(string $email = 'uc-staff@team-uc.test'): User
    {
        $u = User::factory()->create(['provider_id' => $this->tenant['provider']->id, 'email' => $email]);
        $u->assignRole(Role::Staff->value);

        return $u;
    }

    // ── ChangeTeamMemberRole ─────────────────────────────────────────────

    public function test_change_role_swaps_the_single_role_and_records_the_prior_one(): void
    {
        $member = $this->staff();

        $result = $this->changeRole()->handle($member, Role::Admin, $this->tenant['owner']);

        $this->assertTrue($result->fresh()->hasRole(Role::Admin->value));
        $this->assertFalse($result->fresh()->hasRole(Role::Staff->value));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.role_changed',
            'auditable_id' => $member->id,
        ]);
    }

    public function test_change_role_rejects_acting_on_self(): void
    {
        $owner = $this->tenant['owner'];

        $this->expectException(CannotModifyTeamMemberException::class);

        try {
            $this->changeRole()->handle($owner, Role::Staff, $owner);
        } catch (CannotModifyTeamMemberException $e) {
            $this->assertSame(CannotModifyTeamMemberException::REASON_SELF, $e->reason);
            throw $e;
        }
    }

    public function test_change_role_rejects_targeting_the_owner_row(): void
    {
        $otherOwnerCaller = User::factory()->create(['provider_id' => $this->tenant['provider']->id]);
        $otherOwnerCaller->assignRole(Role::Owner->value);

        try {
            $this->changeRole()->handle($this->tenant['owner'], Role::Staff, $otherOwnerCaller);
            $this->fail('Expected CannotModifyTeamMemberException');
        } catch (CannotModifyTeamMemberException $e) {
            $this->assertSame(CannotModifyTeamMemberException::REASON_OWNER, $e->reason);
        }

        $this->assertTrue($this->tenant['owner']->fresh()->hasRole(Role::Owner->value));
    }

    public function test_change_role_to_the_same_value_writes_no_audit_row(): void
    {
        $member = $this->staff();

        $this->changeRole()->handle($member, Role::Staff, $this->tenant['owner']);

        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'user.role_changed',
            'auditable_id' => $member->id,
        ]);
    }

    // ── DeactivateTeamMember ────────────────────────────────────────────

    public function test_deactivate_soft_removes_and_reassigns_matters_to_the_owner(): void
    {
        $member = $this->staff();

        $matter = $this->tenant['matter'];
        $matter->forceFill(['assigned_staff_id' => $member->id])->save();
        $otherMatter = $this->tenant['otherMatter'];
        $otherMatter->forceFill(['assigned_staff_id' => $member->id])->save();

        $this->deactivate()->handle($member, $this->tenant['owner']);

        $member->refresh();
        $this->assertSame('deactivated', $member->status);
        $this->assertNotNull($member->deactivated_at);
        $this->assertDatabaseHas('users', ['id' => $member->id]); // not hard-deleted

        foreach ([$matter->id, $otherMatter->id] as $id) {
            $this->assertNull(
                Matter::query()->acrossWorkspaces()->whereKey($id)->value('assigned_staff_id'),
            );
        }

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.deactivated',
            'auditable_id' => $member->id,
        ]);
    }

    public function test_deactivate_rejects_acting_on_self_and_on_the_owner_row(): void
    {
        $owner = $this->tenant['owner'];

        try {
            $this->deactivate()->handle($owner, $owner);
            $this->fail('Expected CannotModifyTeamMemberException (self)');
        } catch (CannotModifyTeamMemberException $e) {
            $this->assertSame(CannotModifyTeamMemberException::REASON_SELF, $e->reason);
        }

        $caller = User::factory()->create(['provider_id' => $this->tenant['provider']->id]);
        $caller->assignRole(Role::Owner->value);

        try {
            $this->deactivate()->handle($owner, $caller);
            $this->fail('Expected CannotModifyTeamMemberException (owner)');
        } catch (CannotModifyTeamMemberException $e) {
            $this->assertSame(CannotModifyTeamMemberException::REASON_OWNER, $e->reason);
        }

        $this->assertSame('active', $owner->fresh()->status);
    }

    // ── Repository / port units ─────────────────────────────────────────

    public function test_user_repository_sync_role_replaces_not_appends(): void
    {
        $member = $this->staff();
        $member->assignRole(Role::Admin->value); // now holds two

        $this->app->make(UserRepository::class)->syncRole($member, Role::Staff);

        $this->assertEqualsCanonicalizing(['staff'], $member->fresh()->getRoleNames()->all());
    }

    public function test_user_repository_deactivate_sets_status_and_timestamp(): void
    {
        $member = $this->staff();

        $this->app->make(UserRepository::class)->deactivate($member);

        $this->assertSame('deactivated', $member->fresh()->status);
        $this->assertNotNull($member->fresh()->deactivated_at);
    }

    public function test_departing_staff_reassignment_nulls_only_that_users_matters(): void
    {
        $member = $this->staff();
        $keep = $this->tenant['otherMatter'];
        $keep->forceFill(['assigned_staff_id' => $this->tenant['staff']->id])->save();

        $mine = $this->tenant['matter'];
        $mine->forceFill(['assigned_staff_id' => $member->id])->save();

        $changed = $this->app->make(DepartingStaffReassignment::class)
            ->clearMatterAssignments((int) $member->id);

        $this->assertSame(1, $changed);
        $this->assertNull(Matter::query()->acrossWorkspaces()->whereKey($mine->id)->value('assigned_staff_id'));
        $this->assertSame(
            $this->tenant['staff']->id,
            Matter::query()->acrossWorkspaces()->whereKey($keep->id)->value('assigned_staff_id'),
        );
    }
}
