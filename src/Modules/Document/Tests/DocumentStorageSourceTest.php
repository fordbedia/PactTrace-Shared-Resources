<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Document\Tests;

use PactTrackSDK\SharedResources\Modules\Document\Infrastructure\Repositories\Eloquent\EloquentDocumentRepository;
use PactTrackSDK\SharedResources\Modules\Document\Infrastructure\Storage\DocumentStorageSource;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\StorageSource;
use PactTrackSDK\SharedResources\Modules\Workspace\Models\Workspace;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * The `documents` table's contribution to the cross-module storage total.
 * Must span every workspace a provider runs (the plan quota is per-provider),
 * exclude other providers, and exclude soft-deleted rows (they've stopped
 * counting toward the live figure, so the cache must not count them either).
 */
class DocumentStorageSourceTest extends BaseTest
{
    private DocumentStorageSource $source;

    private TestScenarioCollection $tenant;

    private TestScenarioCollection $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->source = new DocumentStorageSource(new EloquentDocumentRepository());
        $this->tenant = ProviderTenantScenario::make('dss-a');
        $this->other = ProviderTenantScenario::make('dss-b');

        Document::query()->delete();
    }

    public function test_it_is_a_registered_storage_source(): void
    {
        $this->assertInstanceOf(StorageSource::class, $this->source);
        $this->assertSame('documents', $this->source->key());
    }

    public function test_it_sums_across_every_workspace_of_the_provider(): void
    {
        $secondWorkspace = Workspace::factory()
            ->forProvider($this->tenant['provider'])
            ->create(['name' => 'dss-a second']);

        $this->document($this->tenant['workspace']->id, 100);
        $this->document($secondWorkspace->id, 250);

        $this->assertSame(350, $this->source->sumBytesForProvider($this->tenant['provider']->id));
    }

    public function test_it_never_counts_another_provider(): void
    {
        $this->document($this->tenant['workspace']->id, 100);

        Document::factory()->create([
            'provider_id' => $this->other['provider']->id,
            'workspace_id' => $this->other['workspace']->id,
            'uploaded_by' => $this->other['owner']->id,
            'size' => 9_999,
        ]);

        $this->assertSame(100, $this->source->sumBytesForProvider($this->tenant['provider']->id));
    }

    public function test_it_excludes_soft_deleted_documents(): void
    {
        $this->document($this->tenant['workspace']->id, 100);
        $removed = $this->document($this->tenant['workspace']->id, 400);
        $removed->delete();

        $this->assertSame(100, $this->source->sumBytesForProvider($this->tenant['provider']->id));
    }

    private function document(int $workspaceId, int $size): Document
    {
        return Document::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $workspaceId,
            'uploaded_by' => $this->tenant['owner']->id,
            'size' => $size,
        ]);
    }
}
