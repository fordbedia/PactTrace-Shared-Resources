<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Tests;

use Illuminate\Support\Facades\Log;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\User\Application\UseCases\ReconcileProviderStorageUsage;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * The nightly `storage:reconcile` safety net — recompute the true total from
 * the StorageSource registry and correct `providers.storage_used_bytes` when
 * the write-time ledger has drifted, logging the drift so the missing
 * increment/decrement path can be found.
 */
class ReconcileProviderStorageUsageTest extends BaseTest
{
    private TestScenarioCollection $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = ProviderTenantScenario::make('recon');
        // The scenario seeds documents of random size — start from a known
        // zero so the recomputed total is exactly what this test creates.
        Document::query()->delete();
    }

    public function test_it_corrects_a_drifted_cache_and_stamps_recalculated_at(): void
    {
        Log::spy();

        $this->document(300);
        $this->tenant['provider']->forceFill([
            'storage_used_bytes' => 999,
            'storage_recalculated_at' => null,
        ])->save();

        $result = app(ReconcileProviderStorageUsage::class)->handle();

        $provider = $this->tenant['provider']->fresh();
        $this->assertSame(300, (int) $provider->storage_used_bytes);
        $this->assertNotNull($provider->storage_recalculated_at);

        $this->assertSame(1, $result['corrected']);
        $this->assertSame(699, $result['drift_bytes']);

        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context): bool {
            return str_contains($message, 'drift')
                && $context['provider_id'] === $this->tenant['provider']->id
                && $context['cached'] === 999
                && $context['actual'] === 300
                && $context['by_source']['documents'] === 300;
        })->once();
    }

    public function test_it_re_confirms_an_already_correct_provider_without_a_drift_log(): void
    {
        Log::spy();

        $this->document(250);
        $this->tenant['provider']->forceFill([
            'storage_used_bytes' => 250,
            'storage_recalculated_at' => null,
        ])->save();

        $result = app(ReconcileProviderStorageUsage::class)->handle();

        $provider = $this->tenant['provider']->fresh();
        $this->assertSame(250, (int) $provider->storage_used_bytes);
        $this->assertNotNull($provider->storage_recalculated_at, 'still stamped as verified');

        $this->assertSame(0, $result['corrected']);
        $this->assertSame(0, $result['drift_bytes']);

        Log::shouldNotHaveReceived('warning');
    }

    private function document(int $size): Document
    {
        return Document::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'uploaded_by' => $this->tenant['owner']->id,
            'size' => $size,
        ]);
    }
}
