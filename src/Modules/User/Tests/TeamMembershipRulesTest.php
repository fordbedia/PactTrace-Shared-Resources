<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Tests;

use PactTrackSDK\SharedResources\Modules\User\Domain\Exceptions\CannotModifyTeamMemberException;
use PactTrackSDK\SharedResources\Modules\User\Domain\Services\TeamMembershipRules;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Role;
use PactTrackSDK\SharedResources\Modules\User\Models\User;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;

/**
 * Pure-logic coverage for the structural invariants shared by "change role"
 * and the status-change actions. These hold in the Application layer
 * regardless of the controller, so they get their own test rather than only
 * being exercised through HTTP.
 *
 * `assertModifiable` (self + owner) is the pair shared by every roster
 * mutation; `assertStatusChangeAllowed` layers the "a non-owner may only act
 * on a Staff member" rule on top, for deactivate/restore only.
 */
class TeamMembershipRulesTest extends BaseTest
{
    private function user(int $id): User
    {
        $u = new User();
        $u->forceFill(['id' => $id]);

        return $u;
    }

    public function test_it_passes_for_an_ordinary_target(): void
    {
        TeamMembershipRules::assertModifiable($this->user(5), $this->user(2), ownerUserId: 2);

        $this->expectNotToPerformAssertions();
    }

    public function test_it_blocks_the_actor_acting_on_themselves(): void
    {
        try {
            TeamMembershipRules::assertModifiable($this->user(2), $this->user(2), ownerUserId: 2);
            $this->fail('Expected CannotModifyTeamMemberException');
        } catch (CannotModifyTeamMemberException $e) {
            $this->assertSame(CannotModifyTeamMemberException::REASON_SELF, $e->reason);
        }
    }

    public function test_it_blocks_targeting_the_provider_owner(): void
    {
        try {
            // Actor 9 is some other owner-role caller; target 2 is the owner row.
            TeamMembershipRules::assertModifiable($this->user(2), $this->user(9), ownerUserId: 2);
            $this->fail('Expected CannotModifyTeamMemberException');
        } catch (CannotModifyTeamMemberException $e) {
            $this->assertSame(CannotModifyTeamMemberException::REASON_OWNER, $e->reason);
        }
    }

    public function test_self_check_wins_when_the_actor_is_the_owner_acting_on_themselves(): void
    {
        try {
            TeamMembershipRules::assertModifiable($this->user(2), $this->user(2), ownerUserId: 2);
            $this->fail('Expected CannotModifyTeamMemberException');
        } catch (CannotModifyTeamMemberException $e) {
            $this->assertSame(CannotModifyTeamMemberException::REASON_SELF, $e->reason);
        }
    }

    public function test_the_exception_carries_a_human_message_per_reason(): void
    {
        $this->assertStringContainsString(
            'yourself',
            CannotModifyTeamMemberException::actingOnSelf()->getMessage(),
        );
        $this->assertStringContainsString(
            'owner',
            CannotModifyTeamMemberException::targetIsOwner()->getMessage(),
        );
        $this->assertStringContainsString(
            'Staff',
            CannotModifyTeamMemberException::targetNotStaff()->getMessage(),
        );
    }

    // ── assertStatusChangeAllowed (deactivate / restore) ──────────────────

    public function test_status_change_allows_the_owner_to_act_on_any_non_owner(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole(Role::Admin->value);

        TeamMembershipRules::assertStatusChangeAllowed($admin, $owner, ownerUserId: (int) $owner->id);

        $this->expectNotToPerformAssertions();
    }

    public function test_status_change_lets_a_non_owner_act_on_a_staff_member(): void
    {
        $admin = User::factory()->create();
        $staff = User::factory()->create();
        $staff->assignRole(Role::Staff->value);

        // Some unrelated user is the owner.
        TeamMembershipRules::assertStatusChangeAllowed($staff, $admin, ownerUserId: 999_999);

        $this->expectNotToPerformAssertions();
    }

    public function test_status_change_blocks_a_non_owner_acting_on_a_non_staff_member(): void
    {
        $admin = User::factory()->create();
        $otherAdmin = User::factory()->create();
        $otherAdmin->assignRole(Role::Admin->value);

        try {
            TeamMembershipRules::assertStatusChangeAllowed($otherAdmin, $admin, ownerUserId: 999_999);
            $this->fail('Expected CannotModifyTeamMemberException');
        } catch (CannotModifyTeamMemberException $e) {
            $this->assertSame(CannotModifyTeamMemberException::REASON_STAFF_ONLY, $e->reason);
        }
    }

    public function test_status_change_still_blocks_self_and_owner_ahead_of_the_staff_check(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::Admin->value);

        try {
            TeamMembershipRules::assertStatusChangeAllowed($admin, $admin, ownerUserId: 999_999);
            $this->fail('Expected CannotModifyTeamMemberException (self)');
        } catch (CannotModifyTeamMemberException $e) {
            $this->assertSame(CannotModifyTeamMemberException::REASON_SELF, $e->reason);
        }

        $owner = User::factory()->create();
        try {
            TeamMembershipRules::assertStatusChangeAllowed($owner, $admin, ownerUserId: (int) $owner->id);
            $this->fail('Expected CannotModifyTeamMemberException (owner)');
        } catch (CannotModifyTeamMemberException $e) {
            $this->assertSame(CannotModifyTeamMemberException::REASON_OWNER, $e->reason);
        }
    }
}
