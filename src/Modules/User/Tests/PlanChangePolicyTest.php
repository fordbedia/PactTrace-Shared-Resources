<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Tests;

use PactTrackSDK\SharedResources\Modules\User\Domain\Services\PlanChangePolicy;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Plan;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\PlanUsageSummary;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;

/**
 * Pure domain-object test — no HTTP, no database — for the downgrade/upgrade
 * usage pre-flight. Scenarios match Ed's own examples from the Stripe
 * billing pass's Verification section.
 */
class PlanChangePolicyTest extends BaseTest
{
    private PlanChangePolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new PlanChangePolicy();
    }

    public function test_firm_to_professional_is_blocked_on_seats(): void
    {
        $usage = new PlanUsageSummary(
            activeClientCount: 5,
            activeStaffCount: 7,
            storageUsedBytes: 1024,
            envelopesSentThisMonth: 0,
        );

        $result = $this->policy->evaluate(Plan::Professional, $usage);

        $this->assertFalse($result->allowed);
        $this->assertCount(1, $result->blockers);
        $this->assertSame('seats', $result->blockers[0]->dimension);
        $this->assertSame(7, $result->blockers[0]->current);
        $this->assertSame(1, $result->blockers[0]->limit);
    }

    public function test_firm_to_professional_is_blocked_on_storage(): void
    {
        $usage = new PlanUsageSummary(
            activeClientCount: 5,
            // 1 non-owner seat — fits Professional's cap, so only storage blocks.
            activeStaffCount: 1,
            storageUsedBytes: 120 * 1024 * 1024 * 1024,
            envelopesSentThisMonth: 0,
        );

        $result = $this->policy->evaluate(Plan::Professional, $usage);

        $this->assertFalse($result->allowed);
        $this->assertCount(1, $result->blockers);
        $this->assertSame('storage', $result->blockers[0]->dimension);
    }

    public function test_professional_to_starter_is_blocked_on_clients(): void
    {
        $usage = new PlanUsageSummary(
            activeClientCount: 20,
            activeStaffCount: 1,
            storageUsedBytes: 1024,
            envelopesSentThisMonth: 0,
        );

        $result = $this->policy->evaluate(Plan::Starter, $usage);

        $this->assertFalse($result->allowed);
        $this->assertCount(1, $result->blockers);
        $this->assertSame('clients', $result->blockers[0]->dimension);
        $this->assertSame(20, $result->blockers[0]->current);
        $this->assertSame(10, $result->blockers[0]->limit);
    }

    public function test_a_downgrade_that_fits_every_dimension_is_allowed(): void
    {
        $usage = new PlanUsageSummary(
            activeClientCount: 3,
            activeStaffCount: 1,
            storageUsedBytes: 1024,
            envelopesSentThisMonth: 5,
        );

        $result = $this->policy->evaluate(Plan::Starter, $usage);

        $this->assertTrue($result->allowed);
        $this->assertSame([], $result->blockers);
    }

    public function test_every_failing_dimension_is_reported_at_once(): void
    {
        $usage = new PlanUsageSummary(
            activeClientCount: 20,
            activeStaffCount: 7,
            storageUsedBytes: 120 * 1024 * 1024 * 1024,
            envelopesSentThisMonth: 0,
        );

        $result = $this->policy->evaluate(Plan::Starter, $usage);

        $this->assertFalse($result->allowed);
        $dimensions = array_map(static fn ($b) => $b->dimension, $result->blockers);
        $this->assertSame(['seats', 'clients', 'storage'], $dimensions);
    }

    public function test_envelopes_per_month_is_never_checked_since_it_is_a_flow_limit(): void
    {
        $usage = new PlanUsageSummary(
            activeClientCount: 1,
            activeStaffCount: 1,
            storageUsedBytes: 1024,
            envelopesSentThisMonth: 999,
        );

        $result = $this->policy->evaluate(Plan::Starter, $usage);

        $this->assertTrue($result->allowed);
    }
}
