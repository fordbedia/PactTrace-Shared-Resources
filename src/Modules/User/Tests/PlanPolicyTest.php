<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Tests;

use PactTrackSDK\SharedResources\Modules\User\Domain\Services\PlanPolicy;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\GatedAction;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\GateDenialReason;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Plan;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\PlanUsageSummary;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;

/**
 * PlanPolicy::evaluate() — pure domain logic, no DB reads or writes; every
 * assertion here is against plain value objects. Still extends BaseTest per
 * the top-level CLAUDE.md's "every unit test class must extend BaseTest"
 * rule (same as PlanInfoTest, also framework-free).
 */
class PlanPolicyTest extends BaseTest
{
    private function usage(
        int $activeClientCount = 0,
        int $activeStaffCount = 0,
        int $storageUsedBytes = 0,
        int $envelopesSentThisCycle = 0,
    ): PlanUsageSummary {
        return new PlanUsageSummary($activeClientCount, $activeStaffCount, $storageUsedBytes, $envelopesSentThisCycle);
    }

    public function test_inactive_subscription_denies_every_action_regardless_of_usage(): void
    {
        $policy = new PlanPolicy();

        foreach (GatedAction::cases() as $action) {
            foreach (['expired', 'past_due', 'canceled', null] as $status) {
                $result = $policy->evaluate($action, Plan::Firm, $status, $this->usage());

                $this->assertFalse($result->allowed, "expected {$action->value} denied for status " . ($status ?? 'null'));
                $this->assertSame(GateDenialReason::SubscriptionInactive, $result->reason);
                // The raw status is carried through so the frontend can tell
                // past_due (card declined) from canceled (subscription ended).
                $this->assertSame($status, $result->subscriptionStatus);
                $this->assertSame($status, $result->toArray()['subscription_status']);
            }
        }
    }

    public function test_trialing_and_active_subscriptions_pass_the_subscription_check(): void
    {
        $policy = new PlanPolicy();

        foreach (['trialing', 'active'] as $status) {
            $result = $policy->evaluate(GatedAction::UploadDocument, Plan::Professional, $status, $this->usage());

            $this->assertTrue($result->allowed);
            $this->assertSame($status, $result->subscriptionStatus);
            $this->assertSame($status, $result->toArray()['subscription_status']);
        }
    }

    public function test_upload_document_denied_at_storage_limit(): void
    {
        $policy = new PlanPolicy();
        $limit = Plan::Starter->info()->storageLimitBytes;

        $atLimit = $policy->evaluate(GatedAction::UploadDocument, Plan::Starter, 'active', $this->usage(storageUsedBytes: $limit));
        $this->assertFalse($atLimit->allowed);
        $this->assertSame(GateDenialReason::PlanLimitExceeded, $atLimit->reason);
        // A plan-limit denial still reports the (active) subscription status.
        $this->assertSame('active', $atLimit->subscriptionStatus);
        $this->assertSame('active', $atLimit->toArray()['subscription_status']);

        $underLimit = $policy->evaluate(GatedAction::UploadDocument, Plan::Starter, 'active', $this->usage(storageUsedBytes: $limit - 1));
        $this->assertTrue($underLimit->allowed);
        $this->assertSame('active', $underLimit->subscriptionStatus);
    }

    public function test_prepare_for_signature_denied_at_monthly_envelope_limit(): void
    {
        $policy = new PlanPolicy();
        $limit = Plan::Starter->info()->maxEnvelopesPerMonth;

        $atLimit = $policy->evaluate(GatedAction::PrepareForSignature, Plan::Starter, 'active', $this->usage(envelopesSentThisCycle: $limit));
        $this->assertFalse($atLimit->allowed);

        $underLimit = $policy->evaluate(GatedAction::PrepareForSignature, Plan::Starter, 'active', $this->usage(envelopesSentThisCycle: $limit - 1));
        $this->assertTrue($underLimit->allowed);
    }

    public function test_invite_client_denied_at_active_client_limit(): void
    {
        $policy = new PlanPolicy();
        $limit = Plan::Starter->info()->maxActiveClients;

        $atLimit = $policy->evaluate(GatedAction::InviteClient, Plan::Starter, 'active', $this->usage(activeClientCount: $limit));
        $this->assertFalse($atLimit->allowed);

        $underLimit = $policy->evaluate(GatedAction::InviteClient, Plan::Starter, 'active', $this->usage(activeClientCount: $limit - 1));
        $this->assertTrue($underLimit->allowed);
    }

    public function test_invite_staff_denied_at_seat_limit(): void
    {
        $policy = new PlanPolicy();
        $limit = Plan::Starter->info()->maxSeats;

        $atLimit = $policy->evaluate(GatedAction::InviteStaff, Plan::Starter, 'active', $this->usage(activeStaffCount: $limit));
        $this->assertFalse($atLimit->allowed);

        $underLimit = $policy->evaluate(GatedAction::InviteStaff, Plan::Starter, 'active', $this->usage(activeStaffCount: $limit - 1));
        $this->assertTrue($underLimit->allowed);
    }

    public function test_unlimited_null_limits_never_deny_regardless_of_usage(): void
    {
        $policy = new PlanPolicy();

        // Professional/Firm: maxActiveClients, maxEnvelopesPerMonth are null (unlimited).
        $result = $policy->evaluate(
            GatedAction::InviteClient,
            Plan::Professional,
            'active',
            $this->usage(activeClientCount: 1_000_000),
        );
        $this->assertTrue($result->allowed);

        $result = $policy->evaluate(
            GatedAction::PrepareForSignature,
            Plan::Professional,
            'active',
            $this->usage(envelopesSentThisCycle: 1_000_000),
        );
        $this->assertTrue($result->allowed);
        $this->assertSame('active', $result->subscriptionStatus);
    }

    public function test_result_carries_the_subscription_status_for_every_outcome(): void
    {
        $policy = new PlanPolicy();

        $pastDue = $policy->evaluate(GatedAction::UploadDocument, Plan::Professional, 'past_due', $this->usage());
        $this->assertFalse($pastDue->allowed);
        $this->assertSame(GateDenialReason::SubscriptionInactive, $pastDue->reason);
        $this->assertSame('past_due', $pastDue->subscriptionStatus);
        $this->assertSame('past_due', $pastDue->toArray()['subscription_status']);
        $this->assertSame('subscription_inactive', $pastDue->toArray()['reason']);

        $canceled = $policy->evaluate(GatedAction::InviteClient, Plan::Firm, 'canceled', $this->usage());
        $this->assertFalse($canceled->allowed);
        $this->assertSame('canceled', $canceled->subscriptionStatus);
        $this->assertSame('canceled', $canceled->toArray()['subscription_status']);

        $active = $policy->evaluate(GatedAction::UploadDocument, Plan::Professional, 'active', $this->usage());
        $this->assertTrue($active->allowed);
        $this->assertSame('active', $active->toArray()['subscription_status']);

        // plan_limit_exceeded on an otherwise-fine active subscription still
        // reports that active status.
        $limitHit = $policy->evaluate(
            GatedAction::InviteClient,
            Plan::Starter,
            'active',
            $this->usage(activeClientCount: Plan::Starter->info()->maxActiveClients),
        );
        $this->assertFalse($limitHit->allowed);
        $this->assertSame('plan_limit_exceeded', $limitHit->toArray()['reason']);
        $this->assertSame('active', $limitHit->toArray()['subscription_status']);
    }

    // ───────────────────────── Pending-downgrade wording ─────────────────
    // When PlanGate resolves the effective plan to a pending (lower) tier it
    // passes viaPendingDowngrade=true so the denial modal can explain the
    // cause. The verdict is unchanged — only the message differs.

    public function test_a_pending_downgrade_denial_names_the_scheduled_change_and_both_fixes(): void
    {
        $policy = new PlanPolicy();

        $result = $policy->evaluate(
            GatedAction::UploadDocument,
            Plan::Starter, // the effective (pending) plan PlanGate resolved
            'active',
            $this->usage(storageUsedBytes: Plan::Starter->info()->storageLimitBytes),
            viaPendingDowngrade: true,
            pendingEffectiveAtLabel: 'Oct 8, 2026',
        );

        $this->assertFalse($result->allowed);
        $this->assertSame('plan_limit_exceeded', $result->toArray()['reason']);
        $this->assertStringContainsString('scheduled downgrade to Starter', $result->message);
        $this->assertStringContainsString('Oct 8, 2026', $result->message);
        $this->assertStringContainsString('5 GB', $result->message);
        $this->assertStringContainsString('Cancel the downgrade', $result->message);
    }

    public function test_pending_downgrade_wording_covers_every_gated_action(): void
    {
        $policy = new PlanPolicy();
        $starter = Plan::Starter->info();

        $cases = [
            [GatedAction::UploadDocument, $this->usage(storageUsedBytes: $starter->storageLimitBytes)],
            [GatedAction::PrepareForSignature, $this->usage(envelopesSentThisCycle: $starter->maxEnvelopesPerMonth)],
            [GatedAction::InviteClient, $this->usage(activeClientCount: $starter->maxActiveClients)],
            [GatedAction::InviteStaff, $this->usage(activeStaffCount: $starter->maxSeats)],
        ];

        foreach ($cases as [$action, $usage]) {
            $result = $policy->evaluate($action, Plan::Starter, 'active', $usage, viaPendingDowngrade: true, pendingEffectiveAtLabel: 'Oct 8, 2026');

            $this->assertFalse($result->allowed, $action->value);
            $this->assertStringContainsString('scheduled downgrade to Starter', $result->message, $action->value);
        }
    }

    public function test_effective_at_label_is_optional_in_the_pending_downgrade_message(): void
    {
        $policy = new PlanPolicy();

        $result = $policy->evaluate(
            GatedAction::InviteClient,
            Plan::Starter,
            'active',
            $this->usage(activeClientCount: Plan::Starter->info()->maxActiveClients),
            viaPendingDowngrade: true,
            pendingEffectiveAtLabel: null,
        );

        $this->assertFalse($result->allowed);
        $this->assertStringContainsString('scheduled downgrade to Starter', $result->message);
        $this->assertStringNotContainsString('effective ,', $result->message); // no dangling comma
    }

    public function test_without_the_pending_flag_the_standard_message_is_used(): void
    {
        $policy = new PlanPolicy();

        $result = $policy->evaluate(
            GatedAction::UploadDocument,
            Plan::Starter,
            'active',
            $this->usage(storageUsedBytes: Plan::Starter->info()->storageLimitBytes),
        );

        $this->assertFalse($result->allowed);
        $this->assertStringContainsString("You've reached your Starter plan's 5 GB storage limit", $result->message);
        $this->assertStringNotContainsString('scheduled downgrade', $result->message);
    }
}
