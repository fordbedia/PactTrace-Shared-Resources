<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Document\Tests;

use InvalidArgumentException;
use PactTrackSDK\SharedResources\Modules\Document\Domain\Ports\StorageQuotas;
use PactTrackSDK\SharedResources\Modules\Document\Domain\ValueObjects\StorageUsage;
use PactTrackSDK\SharedResources\Modules\Document\Infrastructure\Quota\PlanStorageQuotas;
use PactTrackSDK\SharedResources\Modules\Document\Infrastructure\Services\ByteFormatter;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Plan;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The three small pieces the STORAGE indicator is assembled from: the plan
 * allowance (PlanStorageQuotas, reading the Plan enum), the arithmetic
 * (StorageUsage) and the wording (ByteFormatter).
 *
 * The adapter still gets the most attention on its fail-soft contract: an
 * unknown plan string must resolve to the smallest tier, never a bigger
 * allowance, and never an exception that reaches the value object.
 */
class StorageQuotaTest extends BaseTest
{
    private const GB = 1024 * 1024 * 1024;

    private PlanStorageQuotas $quotas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->quotas = new PlanStorageQuotas();
    }

    public function test_it_implements_the_port(): void
    {
        $this->assertInstanceOf(StorageQuotas::class, $this->quotas);
    }

    public function test_it_returns_the_enum_allowance_for_each_plan_string(): void
    {
        $this->assertSame(5 * self::GB, $this->quotas->bytesForPlan('starter'));
        $this->assertSame(50 * self::GB, $this->quotas->bytesForPlan('professional'));
        $this->assertSame(200 * self::GB, $this->quotas->bytesForPlan('firm'));
    }

    public function test_an_unknown_null_or_blank_plan_gets_the_smallest_tier(): void
    {
        $starter = Plan::Starter->storageLimitBytes();

        $this->assertSame($starter, $this->quotas->bytesForPlan(null));
        $this->assertSame($starter, $this->quotas->bytesForPlan(''));
        $this->assertSame($starter, $this->quotas->bytesForPlan('enterprise-plus'));
        $this->assertSame($starter, $this->quotas->bytesForPlan('  '));
        $this->assertSame($starter, $this->quotas->bytesForPlan('STARTER')); // case-sensitive enum
    }

    public function test_the_fallback_is_never_larger_than_the_smallest_paid_tier(): void
    {
        // Failing "open" would show a tenant we cannot identify a bigger
        // allowance than anyone actually buys.
        $fallback = $this->quotas->bytesForPlan('not-a-plan');

        foreach (Plan::cases() as $plan) {
            $this->assertLessThanOrEqual(
                $plan->storageLimitBytes(),
                $fallback,
                "The fallback allowance exceeds the [{$plan->value}] plan.",
            );
        }
    }

    public function test_usage_computes_a_percentage(): void
    {
        $this->assertSame(62.0, (new StorageUsage(62, 100))->percentage());
        $this->assertSame(33.3, (new StorageUsage(1, 3))->percentage(), 'Rounded to one decimal.');
        $this->assertSame(0.0, (new StorageUsage(0, 100))->percentage());
    }

    public function test_usage_clamps_the_percentage_at_one_hundred(): void
    {
        $this->assertSame(100.0, (new StorageUsage(250, 100))->percentage());
    }

    public function test_usage_with_no_allowance_reports_zero_rather_than_dividing_by_zero(): void
    {
        $usage = new StorageUsage(500, 0);

        $this->assertSame(0.0, $usage->percentage());
        $this->assertFalse($usage->isOverLimit());
    }

    public function test_usage_rejects_negative_byte_counts(): void
    {
        // Negative bytes mean the caller computed something wrong; clamping
        // would hide that behind a plausible-looking bar.
        $this->expectException(InvalidArgumentException::class);

        new StorageUsage(-1, 100);
    }

    public function test_usage_reports_what_is_left(): void
    {
        $this->assertSame(40, (new StorageUsage(60, 100))->remainingBytes());
        $this->assertSame(0, (new StorageUsage(160, 100))->remainingBytes(), 'Never negative.');
    }

    #[DataProvider('byteProvider')]
    public function test_the_formatter_words_a_byte_count(int $bytes, string $expected): void
    {
        $this->assertSame($expected, (new ByteFormatter())->format($bytes));
    }

    public static function byteProvider(): array
    {
        return [
            'zero' => [0, '0 B'],
            'bytes' => [512, '512 B'],
            'exactly one kilobyte' => [1024, '1 KB'],
            'kilobytes with a fraction' => [1536, '1.5 KB'],
            'megabytes' => [2_621_440, '2.5 MB'],
            'exactly one gigabyte' => [1024 ** 3, '1 GB'],
            'the artboards figure' => [6_657_199_308, '6.2 GB'],
            'a round plan allowance' => [10 * 1024 ** 3, '10 GB'],
            'terabytes' => [3 * 1024 ** 4, '3 TB'],
            'negative keeps its sign' => [-1536, '-1.5 KB'],
        ];
    }
}
