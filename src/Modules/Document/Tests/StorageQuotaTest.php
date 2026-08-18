<?php

declare(strict_types=1);

namespace PactTraceSDK\SharedResources\Modules\Document\Tests;

use InvalidArgumentException;
use PactTraceSDK\SharedResources\Modules\Document\Domain\Ports\StorageQuotas;
use PactTraceSDK\SharedResources\Modules\Document\Domain\ValueObjects\StorageUsage;
use PactTraceSDK\SharedResources\Modules\Document\Infrastructure\Quota\ConfigStorageQuotas;
use PactTraceSDK\SharedResources\Modules\Document\Infrastructure\Services\ByteFormatter;
use PactTraceSDK\SharedResources\TestCase\Migrations\BaseTest;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The three small pieces the STORAGE indicator is assembled from: the plan
 * allowance (ConfigStorageQuotas), the arithmetic (StorageUsage) and the
 * wording (ByteFormatter).
 *
 * The config adapter gets the most attention because config is hand-editable
 * and every failure mode there is silent: a typo'd key must not hand a
 * starter tenant a firm-sized allowance, and a broken value must not reach
 * the value object and divide by zero.
 */
class StorageQuotaTest extends BaseTest
{
    private const GB = 1024 * 1024 * 1024;

    private ConfigStorageQuotas $quotas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->quotas = new ConfigStorageQuotas(config());
    }

    public function test_it_implements_the_port(): void
    {
        $this->assertInstanceOf(StorageQuotas::class, $this->quotas);
    }

    public function test_the_module_config_ships_an_allowance_for_every_plan(): void
    {
        // The plan values are `providers.plan` (see .claude/rules/user.md) —
        // a plan with no entry would silently fall back to the smallest tier.
        foreach (['starter', 'professional', 'firm'] as $plan) {
            $this->assertGreaterThan(
                0,
                config("document.storage_quota_bytes.{$plan}"),
                "No storage quota configured for the [{$plan}] plan."
            );
        }
    }

    public function test_it_reads_the_allowance_for_a_plan(): void
    {
        config(['document.storage_quota_bytes' => [
            'starter' => 5 * self::GB,
            'firm' => 50 * self::GB,
            'default' => 1 * self::GB,
        ]]);

        $this->assertSame(5 * self::GB, $this->quotas->bytesForPlan('starter'));
        $this->assertSame(50 * self::GB, $this->quotas->bytesForPlan('firm'));
    }

    public function test_an_unknown_or_missing_plan_gets_the_default(): void
    {
        config(['document.storage_quota_bytes' => ['starter' => 5 * self::GB, 'default' => 1 * self::GB]]);

        $this->assertSame(1 * self::GB, $this->quotas->bytesForPlan(null));
        $this->assertSame(1 * self::GB, $this->quotas->bytesForPlan(''));
        $this->assertSame(1 * self::GB, $this->quotas->bytesForPlan('enterprise-plus'));
    }

    public function test_the_default_is_never_larger_than_the_smallest_paid_tier(): void
    {
        // Failing "open" here would show a tenant we cannot identify a bigger
        // allowance than anyone actually buys.
        $configured = config('document.storage_quota_bytes');

        $this->assertLessThanOrEqual(
            $configured['starter'],
            $configured['default'],
            'The fallback allowance must not exceed the cheapest plan.'
        );
    }

    #[DataProvider('brokenConfigProvider')]
    public function test_a_broken_entry_falls_back_to_the_default(mixed $value): void
    {
        config(['document.storage_quota_bytes' => ['starter' => $value, 'default' => 2 * self::GB]]);

        $this->assertSame(2 * self::GB, $this->quotas->bytesForPlan('starter'));
    }

    public static function brokenConfigProvider(): array
    {
        return [
            'a string that is not a number' => ['ten gigabytes'],
            'an array' => [['bytes' => 100]],
            'null' => [null],
            'zero' => [0],
            'negative' => [-1],
            'a float' => [1.5],
        ];
    }

    public function test_a_numeric_string_is_accepted(): void
    {
        // Config can legitimately arrive from an env var, which is a string.
        config(['document.storage_quota_bytes' => ['starter' => '2048', 'default' => 1024]]);

        $this->assertSame(2048, $this->quotas->bytesForPlan('starter'));
    }

    public function test_an_emptied_config_falls_back_to_the_hardcoded_floor(): void
    {
        config(['document.storage_quota_bytes' => []]);

        $this->assertSame(10 * self::GB, $this->quotas->bytesForPlan('starter'));
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
