<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Tests;

use PactTrackSDK\SharedResources\TestCase\Extras\LoadsModuleApiRoutes;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;

/**
 * `GET /api/v1/plans` — the plan catalogue. Served OUTSIDE `auth:sanctum` on
 * purpose: the public marketing pricing page renders its feature bullets from
 * this (so they can't drift from `PlanInfo`) and has no signed-in user. See
 * .claude/rules/plan.md.
 */
class PlanControllerTest extends BaseTest
{
    use LoadsModuleApiRoutes;

    protected function moduleApiRoutes(): array
    {
        return [__DIR__ . '/../routes/api.php'];
    }

    public function test_the_plan_catalogue_is_reachable_without_authentication(): void
    {
        $response = $this->getJson('/api/v1/plans')->assertOk();

        $plans = collect($response->json('data'))->keyBy('plan');

        $this->assertSame(['starter', 'professional', 'firm'], $plans->keys()->all());
    }

    public function test_it_carries_the_audit_log_capability_flags_per_tier(): void
    {
        $plans = collect($this->getJson('/api/v1/plans')->json('data'))->keyBy('plan');

        $this->assertSame(90, $plans['starter']['audit_log_retention_days']);
        $this->assertFalse($plans['starter']['allows_audit_log_export']);

        $this->assertNull($plans['professional']['audit_log_retention_days']);
        $this->assertFalse($plans['professional']['allows_audit_log_export']);

        $this->assertNull($plans['firm']['audit_log_retention_days']);
        $this->assertTrue($plans['firm']['allows_audit_log_export']);
    }
}
