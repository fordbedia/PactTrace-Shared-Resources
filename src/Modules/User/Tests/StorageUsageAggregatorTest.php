<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Tests;

use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\StorageSource;
use PactTrackSDK\SharedResources\Modules\User\Application\Services\StorageUsageAggregator;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;

/**
 * The one place "what counts as storage" is assembled — the sum of every
 * registered {@see StorageSource}. Most of this is pure unit work against
 * fakes; the last test proves the container actually collects both real
 * module sources through the `StorageSource` tag.
 */
class StorageUsageAggregatorTest extends BaseTest
{
    public function test_it_sums_every_source(): void
    {
        $aggregator = new StorageUsageAggregator([
            $this->source('a', 100),
            $this->source('b', 250),
        ]);

        $this->assertSame(350, $aggregator->totalBytesForProvider(1));
    }

    public function test_it_does_not_swallow_a_lone_source(): void
    {
        $aggregator = new StorageUsageAggregator([$this->source('only', 77)]);

        $this->assertSame(77, $aggregator->totalBytesForProvider(1));
    }

    public function test_no_sources_is_zero_not_an_error(): void
    {
        $this->assertSame(0, (new StorageUsageAggregator([]))->totalBytesForProvider(1));
    }

    public function test_a_negative_source_result_is_floored_at_zero(): void
    {
        $aggregator = new StorageUsageAggregator([
            $this->source('bad', -5),
            $this->source('ok', 10),
        ]);

        $this->assertSame(10, $aggregator->totalBytesForProvider(1));
    }

    public function test_it_accepts_a_lazy_iterable_like_the_container_tag(): void
    {
        $generator = (function () {
            yield $this->source('a', 5);
            yield $this->source('b', 6);
        })();

        $this->assertSame(11, (new StorageUsageAggregator($generator))->totalBytesForProvider(1));
    }

    public function test_breakdown_is_keyed_by_source_key(): void
    {
        $aggregator = new StorageUsageAggregator([
            $this->source('documents', 100),
            $this->source('message_attachments', 40),
        ]);

        $this->assertSame(
            ['documents' => 100, 'message_attachments' => 40],
            $aggregator->breakdownForProvider(1),
        );
    }

    public function test_the_bound_aggregator_collects_both_real_module_sources(): void
    {
        // An unknown provider id -> every source returns 0, but the keys
        // present prove DocumentProvider and MessagingProvider both tagged
        // their StorageSource and the aggregator resolved the tag.
        $keys = array_keys(app(StorageUsageAggregator::class)->breakdownForProvider(999_999));

        $this->assertEqualsCanonicalizing(['documents', 'message_attachments'], $keys);
    }

    private function source(string $key, int $bytes): StorageSource
    {
        return new class($key, $bytes) implements StorageSource
        {
            public function __construct(private readonly string $k, private readonly int $b)
            {
            }

            public function sumBytesForProvider(int $providerId): int
            {
                return $this->b;
            }

            public function key(): string
            {
                return $this->k;
            }
        };
    }
}
