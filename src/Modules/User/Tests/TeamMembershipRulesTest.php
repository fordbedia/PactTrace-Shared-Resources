<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Tests;

use PactTrackSDK\SharedResources\Modules\User\Domain\Exceptions\CannotModifyTeamMemberException;
use PactTrackSDK\SharedResources\Modules\User\Domain\Services\TeamMembershipRules;
use PactTrackSDK\SharedResources\Modules\User\Models\User;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;

/**
 * Pure-logic coverage for the two structural invariants shared by "change
 * role" and "remove teammate". These hold in the Application layer regardless
 * of the controller, so they get their own test rather than only being
 * exercised through HTTP.
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
    }
}
