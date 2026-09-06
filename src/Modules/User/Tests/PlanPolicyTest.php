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
        int $envelopesSentThisMonth = 0,
    ): PlanUsageSummary {
        return new PlanUsageSummary($activeClientCount, $activeStaffCount, $storageUsedBytes, $envelopesSentThisMonth);
    }

    public function test_inactive_subscription_denies_every_action_regardless_of_usage(): void
    {
        $policy = new PlanPolicy();

        foreach (GatedAction::cases() as $action) {
            foreach (['expired', 'past_due', 'canceled', null] as $status) {
                $result = $policy->evaluate($action, Plan::Firm, $status, $this->usage());

                $this->assertFalse($result->allowed, "expected {$action->value} denied for status " . ($status ?? 'null'));
                $this->assertSame(GateDenialReason::SubscriptionInactive, $result->reason);
            }
        }
    }

    public function test_trialing_and_active_subscriptions_pass_the_subscription_check(): void
    {
        $policy = new PlanPolicy();

        foreach (['trialing', 'active'] as $status) {
            $result = $policy->evaluate(GatedAction::UploadDocument, Plan::Professional, $status, $this->usage());

            $this->assertTrue($result->allowed);
        }
    }

    public function test_upload_document_denied_at_storage_limit(): void
    {
        $policy = new PlanPolicy();
        $limit = Plan::Starter->info()->storageLimitBytes;

        $atLimit = $policy->evaluate(GatedAction::UploadDocument, Plan::Starter, 'active', $this->usage(storageUsedBytes: $limit));
        $this->assertFalse($atLimit->allowed);
        $this->assertSame(GateDenialReason::PlanLimitExceeded, $atLimit->reason);

        $underLimit = $policy->evaluate(GatedAction::UploadDocument, Plan::Starter, 'active', $this->usage(storageUsedBytes: $limit - 1));
        $this->assertTrue($underLimit->allowed);
    }

    public function test_prepare_for_signature_denied_at_monthly_envelope_limit(): void
    {
        $policy = new PlanPolicy();
        $limit = Plan::Starter->info()->maxEnvelopesPerMonth;

        $atLimit = $policy->evaluate(GatedAction::PrepareForSignature, Plan::Starter, 'active', $this->usage(envelopesSentThisMonth: $limit));
        $this->assertFalse($atLimit->allowed);

        $underLimit = $policy->evaluate(GatedAction::PrepareForSignature, Plan::Starter, 'active', $this->usage(envelopesSentThisMonth: $limit - 1));
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
            $this->usage(envelopesSentThisMonth: 1_000_000),
        );
        $this->assertTrue($result->allowed);
    }
}
