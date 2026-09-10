<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Tests;

use PactTrackSDK\SharedResources\Modules\User\Domain\Services\PlanChangePolicy;
use PactTrackSDK\SharedResources\Modules\User\Domain\Services\PortalDowngradeAvailability;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Plan;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\PlanUsageSummary;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;

/**
 * Pure domain-object test — no HTTP, no DB — for "can this tenant downgrade
 * to *any* lower tier right now". Composes the real {@see PlanChangePolicy}
 * (seats 1/1/5, clients 10/∞/∞, storage 5/50/200 GB).
 */
class PortalDowngradeAvailabilityTest extends BaseTest
{
    private const GB = 1024 * 1024 * 1024;

    private PortalDowngradeAvailability $availability;

    protected function setUp(): void
    {
        parent::setUp();

        $this->availability = new PortalDowngradeAvailability;
    }

    private function usage(int $clients, int $staff, int $storageGb): PlanUsageSummary
    {
        return new PlanUsageSummary(
            activeClientCount: $clients,
            activeStaffCount: $staff,
            storageUsedBytes: $storageGb * self::GB,
            envelopesSentThisCycle: 0,
        );
    }

    public function test_a_firm_tenant_with_modest_usage_can_reach_every_lower_tier(): void
    {
        $this->assertTrue(
            $this->availability->anyLowerTierFits(Plan::Firm, $this->usage(clients: 3, staff: 1, storageGb: 1))
        );
    }

    public function test_a_firm_tenant_over_starters_limits_still_fits_professional(): void
    {
        // 40 clients + 30 GB busts Starter (10 clients / 5 GB) but sits inside
        // Professional (unlimited clients / 50 GB, 1 seat) — a downgrade is
        // still available, just not all the way down.
        $this->assertTrue(
            $this->availability->anyLowerTierFits(Plan::Firm, $this->usage(clients: 40, staff: 1, storageGb: 30))
        );
    }

    public function test_a_firm_tenant_over_every_lower_tier_has_no_downgrade_available(): void
    {
        // 3 seats fits Firm (5) but not Professional/Starter (1); 60 GB busts
        // Professional's 50 GB too. Nothing below Firm fits.
        $this->assertFalse(
            $this->availability->anyLowerTierFits(Plan::Firm, $this->usage(clients: 4, staff: 3, storageGb: 60))
        );
    }

    public function test_a_professional_tenant_that_fits_starter_can_downgrade(): void
    {
        $this->assertTrue(
            $this->availability->anyLowerTierFits(Plan::Professional, $this->usage(clients: 3, staff: 1, storageGb: 1))
        );
    }

    public function test_a_professional_tenant_over_starters_client_cap_cannot(): void
    {
        $this->assertFalse(
            $this->availability->anyLowerTierFits(Plan::Professional, $this->usage(clients: 20, staff: 1, storageGb: 1))
        );
    }

    public function test_a_starter_tenant_has_no_lower_tier_at_all(): void
    {
        // Even at zero usage there is nothing below Starter to move to.
        $this->assertFalse(
            $this->availability->anyLowerTierFits(Plan::Starter, $this->usage(clients: 0, staff: 0, storageGb: 0))
        );
    }
}
