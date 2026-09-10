<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Tests;

use PactTrackSDK\SharedResources\Modules\User\Domain\Services\EffectivePlan;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Plan;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;

/**
 * Pure domain-object test — no DB — for "which plan's limits apply right
 * now". A scheduled Portal downgrade means the pending (lower) tier applies
 * immediately; see .claude/rules/plan.md, "Pending downgrade".
 */
class EffectivePlanTest extends BaseTest
{
    public function test_with_no_pending_plan_the_current_plan_applies(): void
    {
        $effective = EffectivePlan::resolve('firm', null, null);

        $this->assertSame(Plan::Firm, $effective->plan);
        $this->assertFalse($effective->viaPendingDowngrade);
        $this->assertNull($effective->effectiveAtIso);
    }

    public function test_a_pending_downgrade_makes_the_lower_plan_effective_immediately(): void
    {
        $effective = EffectivePlan::resolve('firm', 'starter', '2026-10-08T17:00:00+00:00');

        $this->assertSame(Plan::Starter, $effective->plan);
        $this->assertTrue($effective->viaPendingDowngrade);
        $this->assertSame('2026-10-08T17:00:00+00:00', $effective->effectiveAtIso);
    }

    public function test_firm_to_professional_pending_downgrade_resolves_to_professional(): void
    {
        $effective = EffectivePlan::resolve('firm', 'professional', '2026-11-01T00:00:00+00:00');

        $this->assertSame(Plan::Professional, $effective->plan);
        $this->assertTrue($effective->viaPendingDowngrade);
    }

    public function test_a_pending_value_that_is_not_lower_is_ignored(): void
    {
        // Stripe never schedules an upgrade, but a stale/odd phase must never
        // be able to *raise* the effective plan.
        $effective = EffectivePlan::resolve('starter', 'firm', '2026-10-08T00:00:00+00:00');

        $this->assertSame(Plan::Starter, $effective->plan);
        $this->assertFalse($effective->viaPendingDowngrade);
        $this->assertNull($effective->effectiveAtIso);
    }

    public function test_a_pending_value_equal_to_the_current_plan_is_ignored(): void
    {
        $effective = EffectivePlan::resolve('professional', 'professional', '2026-10-08T00:00:00+00:00');

        $this->assertSame(Plan::Professional, $effective->plan);
        $this->assertFalse($effective->viaPendingDowngrade);
    }

    public function test_an_unrecognised_pending_string_is_ignored(): void
    {
        $effective = EffectivePlan::resolve('firm', 'garbage', null);

        $this->assertSame(Plan::Firm, $effective->plan);
        $this->assertFalse($effective->viaPendingDowngrade);
    }

    public function test_an_unknown_current_plan_falls_back_to_the_default_tier(): void
    {
        $effective = EffectivePlan::resolve(null, null, null);

        $this->assertSame(Plan::default(), $effective->plan);
        $this->assertSame(Plan::Starter, $effective->plan);
    }

    public function test_a_pending_downgrade_still_resolves_when_the_current_plan_is_unknown(): void
    {
        // null current → default (Starter); 'starter' pending is not lower, so ignored.
        $effective = EffectivePlan::resolve(null, 'starter', '2026-10-08T00:00:00+00:00');

        $this->assertSame(Plan::Starter, $effective->plan);
        $this->assertFalse($effective->viaPendingDowngrade);
    }
}
