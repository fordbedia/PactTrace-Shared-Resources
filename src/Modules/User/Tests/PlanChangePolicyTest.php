<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Tests;

use PactTrackSDK\SharedResources\Modules\User\Domain\Services\PlanChangePolicy;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Plan;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\PlanUsageSummary;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PHPUnit\Framework\Attributes\DataProvider;

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
            envelopesSentThisCycle: 0,
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
            envelopesSentThisCycle: 0,
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
            envelopesSentThisCycle: 0,
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
            envelopesSentThisCycle: 5,
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
            envelopesSentThisCycle: 0,
        );

        $result = $this->policy->evaluate(Plan::Starter, $usage);

        $this->assertFalse($result->allowed);
        $dimensions = array_map(static fn ($b) => $b->dimension, $result->blockers);
        $this->assertSame(['seats', 'clients', 'storage'], $dimensions);
    }

    public function test_envelopes_per_cycle_is_never_checked_since_it_is_a_flow_limit(): void
    {
        $usage = new PlanUsageSummary(
            activeClientCount: 1,
            activeStaffCount: 1,
            storageUsedBytes: 1024,
            envelopesSentThisCycle: 999,
        );

        $result = $this->policy->evaluate(Plan::Starter, $usage);

        $this->assertTrue($result->allowed);
    }

    // ───────────────────────── Boundary: `>` (over), not `>=` (at) ─────────
    // A plan change is a one-time move — being *exactly at* the target plan's
    // limit fits. (Contrast PlanPolicy's write-gate, which is `>=`: you can't
    // *add* a row when already at the limit. PlanPolicyTest covers that side.)

    private const GB = 1024 * 1024 * 1024;

    #[DataProvider('atLimitCases')]
    public function test_usage_exactly_at_the_target_limit_is_allowed(Plan $target, array $usageArgs): void
    {
        $result = $this->policy->evaluate($target, new PlanUsageSummary(...$usageArgs));

        $this->assertTrue($result->allowed, 'exactly at the limit must fit a plan change');
        $this->assertSame([], $result->blockers);
    }

    public static function atLimitCases(): array
    {
        return [
            'starter: 10 clients (== cap)' => [Plan::Starter, [
                'activeClientCount' => 10, 'activeStaffCount' => 1, 'storageUsedBytes' => 1024, 'envelopesSentThisCycle' => 0,
            ]],
            'starter: 5 GB storage (== cap)' => [Plan::Starter, [
                'activeClientCount' => 1, 'activeStaffCount' => 1, 'storageUsedBytes' => 5 * self::GB, 'envelopesSentThisCycle' => 0,
            ]],
            'professional: 1 seat (== cap)' => [Plan::Professional, [
                'activeClientCount' => 999, 'activeStaffCount' => 1, 'storageUsedBytes' => 1024, 'envelopesSentThisCycle' => 0,
            ]],
            'firm: 5 seats (== cap)' => [Plan::Firm, [
                'activeClientCount' => 1, 'activeStaffCount' => 5, 'storageUsedBytes' => 1024, 'envelopesSentThisCycle' => 0,
            ]],
        ];
    }

    #[DataProvider('oneOverCases')]
    public function test_usage_one_over_the_target_limit_is_blocked_on_that_dimension(
        Plan $target,
        array $usageArgs,
        string $expectedDimension,
    ): void {
        $result = $this->policy->evaluate($target, new PlanUsageSummary(...$usageArgs));

        $this->assertFalse($result->allowed);
        $this->assertCount(1, $result->blockers);
        $this->assertSame($expectedDimension, $result->blockers[0]->dimension);
        $this->assertNotSame('', $result->blockers[0]->message);
    }

    public static function oneOverCases(): array
    {
        return [
            'starter: 11 clients' => [Plan::Starter, [
                'activeClientCount' => 11, 'activeStaffCount' => 1, 'storageUsedBytes' => 1024, 'envelopesSentThisCycle' => 0,
            ], 'clients'],
            'starter: 5 GB + 1 byte' => [Plan::Starter, [
                'activeClientCount' => 1, 'activeStaffCount' => 1, 'storageUsedBytes' => 5 * self::GB + 1, 'envelopesSentThisCycle' => 0,
            ], 'storage'],
            'professional: 2 seats' => [Plan::Professional, [
                'activeClientCount' => 1, 'activeStaffCount' => 2, 'storageUsedBytes' => 1024, 'envelopesSentThisCycle' => 0,
            ], 'seats'],
            'professional: 50 GB + 1 byte' => [Plan::Professional, [
                'activeClientCount' => 1, 'activeStaffCount' => 1, 'storageUsedBytes' => 50 * self::GB + 1, 'envelopesSentThisCycle' => 0,
            ], 'storage'],
            'firm: 6 seats' => [Plan::Firm, [
                'activeClientCount' => 1, 'activeStaffCount' => 6, 'storageUsedBytes' => 1024, 'envelopesSentThisCycle' => 0,
            ], 'seats'],
        ];
    }

    // ───────────────────────── Direction: upgrades / same-plan ────────────

    public function test_an_upgrade_is_always_allowed_when_usage_fits_the_target(): void
    {
        // Usage that busts Starter (500 clients, 190 GB) but sits inside Firm
        // (unlimited clients, 200 GB, 5 seats).
        $bustsStarterFitsFirm = new PlanUsageSummary(
            activeClientCount: 500,
            activeStaffCount: 5,
            storageUsedBytes: 190 * self::GB,
            envelopesSentThisCycle: 9999,
        );

        $this->assertTrue($this->policy->evaluate(Plan::Firm, $bustsStarterFitsFirm)->allowed);
    }

    public function test_a_same_plan_change_is_always_allowed(): void
    {
        $heavyFirmUsage = new PlanUsageSummary(
            activeClientCount: 400,
            activeStaffCount: 5,
            storageUsedBytes: 190 * self::GB,
            envelopesSentThisCycle: 100,
        );

        $this->assertTrue($this->policy->evaluate(Plan::Firm, $heavyFirmUsage)->allowed);
    }

    // ───────────────────────── Professional target: clients never block ───

    public function test_downgrading_to_professional_never_blocks_on_clients_since_professional_is_unlimited(): void
    {
        $usage = new PlanUsageSummary(
            activeClientCount: 300,
            activeStaffCount: 1,
            storageUsedBytes: 1024,
            envelopesSentThisCycle: 0,
        );

        $result = $this->policy->evaluate(Plan::Professional, $usage);

        $this->assertTrue($result->allowed);
    }

    // ───────────────────────── Firm → Starter: the worst case ────────────

    public function test_firm_to_starter_blocked_on_clients_in_isolation(): void
    {
        $usage = new PlanUsageSummary(
            activeClientCount: 40,
            activeStaffCount: 1,
            storageUsedBytes: 1024,
            envelopesSentThisCycle: 0,
        );

        $result = $this->policy->evaluate(Plan::Starter, $usage);

        $this->assertFalse($result->allowed);
        $this->assertSame(['clients'], array_map(static fn ($b) => $b->dimension, $result->blockers));
        $this->assertSame(40, $result->blockers[0]->current);
        $this->assertSame(10, $result->blockers[0]->limit);
    }

    public function test_firm_to_starter_reports_every_dimension_with_real_numbers_and_messages(): void
    {
        $usage = new PlanUsageSummary(
            activeClientCount: 40,
            activeStaffCount: 4,
            storageUsedBytes: 18 * self::GB,
            envelopesSentThisCycle: 250,
        );

        $result = $this->policy->evaluate(Plan::Starter, $usage);

        $this->assertFalse($result->allowed);
        $this->assertSame(['seats', 'clients', 'storage'], array_map(static fn ($b) => $b->dimension, $result->blockers));

        $byDimension = [];
        foreach ($result->blockers as $b) {
            $this->assertNotSame('', $b->message);
            $byDimension[$b->dimension] = $b;
        }

        // seats/clients spell out the raw count; storage uses the human label.
        $this->assertStringContainsString('4', $byDimension['seats']->message);
        $this->assertStringContainsString('40', $byDimension['clients']->message);
        $this->assertStringContainsString('5 GB', $byDimension['storage']->message);
    }

    public function test_a_firm_tenant_that_has_trimmed_down_can_downgrade_all_the_way_to_starter(): void
    {
        $usage = new PlanUsageSummary(
            activeClientCount: 9,
            activeStaffCount: 1,
            storageUsedBytes: 4 * self::GB,
            envelopesSentThisCycle: 200, // flow limit — never pre-checked
        );

        $result = $this->policy->evaluate(Plan::Starter, $usage);

        $this->assertTrue($result->allowed);
        $this->assertSame([], $result->blockers);
    }
}
