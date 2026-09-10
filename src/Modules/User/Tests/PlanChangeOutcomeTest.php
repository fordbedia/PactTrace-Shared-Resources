<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Tests;

use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Plan;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\PlanChangeOutcome;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;

/**
 * Pure domain-object test — no DB — for what {@see \PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Billing\ChangeSubscriptionPlan}
 * reports it did. See .claude/rules/plan.md, "Change-plan confirmation modal".
 */
class PlanChangeOutcomeTest extends BaseTest
{
    public function test_immediate_carries_the_status_and_target_plan(): void
    {
        $outcome = PlanChangeOutcome::immediate(Plan::Firm);

        $this->assertSame(PlanChangeOutcome::IMMEDIATE, $outcome->status);
        $this->assertSame('immediate', $outcome->status);
        $this->assertSame(Plan::Firm, $outcome->targetPlan);
    }

    public function test_no_change_carries_the_noop_status(): void
    {
        $outcome = PlanChangeOutcome::noChange(Plan::Starter);

        $this->assertSame(PlanChangeOutcome::NOOP, $outcome->status);
        $this->assertSame('noop', $outcome->status);
        $this->assertSame(Plan::Starter, $outcome->targetPlan);
    }

    public function test_to_array_is_snake_cased_and_carries_the_plan_label(): void
    {
        $this->assertSame(
            [
                'status' => 'immediate',
                'target_plan' => 'professional',
                'target_plan_label' => Plan::Professional->label(),
            ],
            PlanChangeOutcome::immediate(Plan::Professional)->toArray(),
        );

        $this->assertSame('noop', PlanChangeOutcome::noChange(Plan::Firm)->toArray()['status']);
    }
}
