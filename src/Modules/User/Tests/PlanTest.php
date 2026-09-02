<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Tests;

use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Plan;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The Plan enum — the one source of truth for what plans exist and what each
 * allows. Storage allowances used to live in a separate config array; these
 * assertions are what stops the two drifting again.
 */
class PlanTest extends BaseTest
{
    private const GB = 1024 * 1024 * 1024;

    public function test_values_lists_every_plan_string(): void
    {
        $this->assertSame(['starter', 'professional', 'firm'], Plan::values());
    }

    public function test_the_default_is_the_smallest_tier(): void
    {
        $this->assertSame(Plan::Starter, Plan::default());

        foreach (Plan::cases() as $plan) {
            $this->assertLessThanOrEqual(
                $plan->storageLimitBytes(),
                Plan::default()->storageLimitBytes(),
            );
        }
    }

    #[DataProvider('storageLimits')]
    public function test_storage_limit_bytes(Plan $plan, int $expectedGb): void
    {
        $this->assertSame($expectedGb * self::GB, $plan->storageLimitBytes());
    }

    public static function storageLimits(): array
    {
        return [
            'starter is 5 GB' => [Plan::Starter, 5],
            'professional is 50 GB' => [Plan::Professional, 50],
            'firm is 200 GB' => [Plan::Firm, 200],
        ];
    }

    public function test_label(): void
    {
        $this->assertSame('Starter', Plan::Starter->label());
        $this->assertSame('Professional', Plan::Professional->label());
        $this->assertSame('Firm', Plan::Firm->label());
    }

    public function test_try_from_a_bad_string_is_null(): void
    {
        $this->assertNull(Plan::tryFrom('enterprise'));
        $this->assertNull(Plan::tryFrom(''));
    }
}
