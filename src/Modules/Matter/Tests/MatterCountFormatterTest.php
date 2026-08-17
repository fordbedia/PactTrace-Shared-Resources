<?php

declare(strict_types=1);

namespace PactTraceSDK\SharedResources\Modules\Matter\Tests;

use PactTraceSDK\SharedResources\Modules\Matter\Infrastructure\Services\MatterCountFormatter;
use PactTraceSDK\SharedResources\TestCase\Migrations\BaseTest;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Covers the k/M/B/T/Z abbreviation used by the /dashboard/matters stat
 * cards (MattersController::stats()) — see .claude/rules/matter.md.
 */
class MatterCountFormatterTest extends BaseTest
{
    private MatterCountFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->formatter = new MatterCountFormatter();
    }

    #[DataProvider('countProvider')]
    public function test_it_abbreviates_counts(int $count, string $expected): void
    {
        $this->assertSame($expected, $this->formatter->format($count));
    }

    public static function countProvider(): array
    {
        return [
            'zero' => [0, '0'],
            'below the thousands threshold' => [999, '999'],
            'exactly one thousand' => [1_000, '1k'],
            'thousands with a fraction' => [3_400, '3.4k'],
            'thousands rounds to one decimal' => [3_456, '3.5k'],
            'exactly one million' => [1_000_000, '1M'],
            'millions with a fraction' => [7_800_000, '7.8M'],
            'exactly one billion' => [1_000_000_000, '1B'],
            'billions with a fraction' => [3_100_000_000, '3.1B'],
            'exactly one trillion' => [1_000_000_000_000, '1T'],
            'trillions with a fraction' => [5_300_000_000_000, '5.3T'],
            'exactly one zillion' => [1_000_000_000_000_000, '1Z'],
            'zillions with a fraction' => [4_400_000_000_000_000, '4.4Z'],
            'negative counts keep their sign' => [-3_400, '-3.4k'],
        ];
    }
}
