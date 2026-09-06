<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Tests;

use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Plan;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\PlanInfo;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;

/**
 * PlanInfo::for() — the file every future feature gate depends on. The matrix
 * in .claude/rules/plan.md is the spec these assertions pin down; a change to
 * one without the other should fail here first.
 */
class PlanInfoTest extends BaseTest
{
    private const GB = 1024 * 1024 * 1024;

    public function test_starter(): void
    {
        $info = Plan::Starter->info();

        $this->assertSame(Plan::Starter, $info->plan);
        $this->assertSame('Starter', $info->label);
        $this->assertSame(1, $info->maxSeats);
        $this->assertSame(10, $info->maxActiveClients);
        $this->assertSame(5 * self::GB, $info->storageLimitBytes);
        $this->assertSame('5 GB', $info->storageLimitLabel);
        $this->assertSame(10, $info->maxEnvelopesPerMonth);
        $this->assertSame(90, $info->auditLogRetentionDays);
        $this->assertFalse($info->allowsAuditLogExport);
        $this->assertFalse($info->allowsCustomDomain);
        $this->assertFalse($info->allowsCustomBranding);
        $this->assertSame('standard', $info->supportTier);
    }

    public function test_professional(): void
    {
        $info = Plan::Professional->info();

        $this->assertSame(Plan::Professional, $info->plan);
        $this->assertSame('Professional', $info->label);
        $this->assertSame(1, $info->maxSeats);
        $this->assertNull($info->maxActiveClients);
        $this->assertSame(50 * self::GB, $info->storageLimitBytes);
        $this->assertSame('50 GB', $info->storageLimitLabel);
        $this->assertNull($info->maxEnvelopesPerMonth);
        $this->assertNull($info->auditLogRetentionDays);
        $this->assertFalse($info->allowsAuditLogExport);
        $this->assertTrue($info->allowsCustomDomain);
        $this->assertTrue($info->allowsCustomBranding);
        $this->assertSame('standard', $info->supportTier);
    }

    public function test_firm(): void
    {
        $info = Plan::Firm->info();

        $this->assertSame(Plan::Firm, $info->plan);
        $this->assertSame('Firm', $info->label);
        $this->assertSame(5, $info->maxSeats);
        $this->assertNull($info->maxActiveClients);
        $this->assertSame(200 * self::GB, $info->storageLimitBytes);
        $this->assertSame('200 GB', $info->storageLimitLabel);
        $this->assertNull($info->maxEnvelopesPerMonth);
        $this->assertNull($info->auditLogRetentionDays);
        $this->assertTrue($info->allowsAuditLogExport);
        $this->assertTrue($info->allowsCustomDomain);
        $this->assertTrue($info->allowsCustomBranding);
        $this->assertSame('priority_sla', $info->supportTier);
    }

    public function test_to_array_is_snake_cased_and_complete(): void
    {
        $array = Plan::Professional->info()->toArray();

        $this->assertSame([
            'plan' => 'professional',
            'label' => 'Professional',
            'max_seats' => 1,
            'max_active_clients' => null,
            'storage_limit_bytes' => 50 * self::GB,
            'storage_limit_label' => '50 GB',
            'max_envelopes_per_month' => null,
            'audit_log_retention_days' => null,
            'allows_audit_log_export' => false,
            'allows_custom_domain' => true,
            'allows_custom_branding' => true,
            'support_tier' => 'standard',
        ], $array);
    }

    public function test_for_matches_the_info_accessor(): void
    {
        foreach (Plan::cases() as $plan) {
            $this->assertEquals(PlanInfo::for($plan), $plan->info());
        }
    }
}
