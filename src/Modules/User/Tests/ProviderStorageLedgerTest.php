<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Tests;

use PactTrackSDK\SharedResources\Modules\User\Application\Services\ProviderStorageLedger;
use PactTrackSDK\SharedResources\Modules\User\Models\Provider;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;

/**
 * The write-time maintainer of the cached `providers.storage_used_bytes`
 * column. Each adjustment must be a single atomic UPDATE (so concurrent
 * uploads can't lose one another's delta) and must never drive the counter
 * negative.
 */
class ProviderStorageLedgerTest extends BaseTest
{
    private ProviderStorageLedger $ledger;

    private Provider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ledger = new ProviderStorageLedger();
        $this->provider = Provider::factory()->create(['storage_used_bytes' => 0]);
    }

    public function test_credit_increments_the_column(): void
    {
        $this->ledger->credit($this->provider->id, 500);

        $this->assertSame(500, $this->used());
    }

    public function test_debit_decrements_the_column(): void
    {
        $this->ledger->credit($this->provider->id, 500);
        $this->ledger->debit($this->provider->id, 200);

        $this->assertSame(300, $this->used());
    }

    public function test_debit_clamps_at_zero(): void
    {
        $this->ledger->credit($this->provider->id, 100);
        $this->ledger->debit($this->provider->id, 999);

        $this->assertSame(0, $this->used());
    }

    public function test_non_positive_amounts_are_a_no_op(): void
    {
        $this->ledger->credit($this->provider->id, 250);
        $this->ledger->credit($this->provider->id, 0);
        $this->ledger->credit($this->provider->id, -40);
        $this->ledger->debit($this->provider->id, 0);

        $this->assertSame(250, $this->used());
    }

    public function test_two_consecutive_credits_both_land(): void
    {
        // No lost update: each call is an atomic UPDATE, not a
        // read-in-PHP-then-write. A read-modify-write ledger would drop one
        // of these.
        $this->ledger->credit($this->provider->id, 120);
        $this->ledger->credit($this->provider->id, 300);

        $this->assertSame(420, $this->used());
    }

    public function test_it_only_touches_the_named_provider(): void
    {
        $other = Provider::factory()->create(['storage_used_bytes' => 1_000]);

        $this->ledger->credit($this->provider->id, 50);

        $this->assertSame(1_000, (int) $other->fresh()->storage_used_bytes);
        $this->assertSame(50, $this->used());
    }

    private function used(): int
    {
        return (int) $this->provider->fresh()->storage_used_bytes;
    }
}
