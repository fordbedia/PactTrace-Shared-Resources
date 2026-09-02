<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Document\Tests;

use PactTrackSDK\SharedResources\Modules\Document\Application\Port\Repository\DocumentRepository;
use PactTrackSDK\SharedResources\Modules\Document\Infrastructure\Repositories\Eloquent\EloquentDocumentRepository;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\Document\Models\Folder;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * The Eloquent adapter behind the DocumentRepository port — the queries the
 * document table on /dashboard/documents actually runs.
 *
 * Two tenants throughout, because every method here takes a `$providerId` and
 * the failure mode that matters is not "returns nothing" but "returns the
 * other tenant's rows". A single-tenant fixture cannot catch that.
 */
class EloquentDocumentRepositoryTest extends BaseTest
{
    private EloquentDocumentRepository $repository;

    private TestScenarioCollection $tenant;

    private TestScenarioCollection $otherTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new EloquentDocumentRepository();
        $this->tenant = ProviderTenantScenario::make('docrepo-a');
        $this->otherTenant = ProviderTenantScenario::make('docrepo-b');
    }

    public function test_it_is_the_bound_implementation_of_the_port(): void
    {
        $this->assertInstanceOf(EloquentDocumentRepository::class, app(DocumentRepository::class));
    }

    public function test_create_persists_a_document(): void
    {
        $document = $this->repository->create([
            'provider_id' => $this->tenant['provider']->id,
            'client_id' => $this->tenant['client']->id,
            'uploaded_by' => $this->tenant['owner']->id,
            'name' => 'retainer.pdf',
            's3_path' => 'documents/1/retainer.pdf',
            'mime_type' => 'application/pdf',
            'size' => 2048,
            'version' => 1,
        ]);

        $this->assertInstanceOf(Document::class, $document);
        $this->assertTrue($document->exists);
        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'name' => 'retainer.pdf',
            's3_path' => 'documents/1/retainer.pdf',
        ]);
    }

    public function test_for_provider_returns_only_that_providers_documents(): void
    {
        $page = $this->repository->forProvider($this->tenant['provider']->id, null, 15, 1);

        $providerIds = $page->getCollection()->pluck('provider_id')->unique()->all();

        $this->assertSame([$this->tenant['provider']->id], $providerIds);
        // The scenario seeds two documents per tenant (one per client).
        $this->assertSame(2, $page->total());
    }

    public function test_for_provider_orders_newest_first(): void
    {
        $older = $this->documentFor($this->tenant, ['created_at' => now()->subDays(3)]);
        $newer = $this->documentFor($this->tenant, ['created_at' => now()->addDay()]);

        $ids = $this->repository->forProvider($this->tenant['provider']->id, null, 15, 1)
            ->getCollection()
            ->pluck('id')
            ->all();

        $this->assertSame($newer->id, $ids[0], 'The newest document should sort first.');
        $this->assertSame($older->id, end($ids), 'The oldest document should sort last.');
    }

    public function test_for_provider_paginates(): void
    {
        // Two from the scenario plus three more = five documents, 2 per page.
        for ($i = 0; $i < 3; $i++) {
            $this->documentFor($this->tenant);
        }

        $firstPage = $this->repository->forProvider($this->tenant['provider']->id, null, 2, 1);
        $lastPage = $this->repository->forProvider($this->tenant['provider']->id, null, 2, 3);

        $this->assertSame(5, $firstPage->total());
        $this->assertSame(3, $firstPage->lastPage());
        $this->assertCount(2, $firstPage->items());
        $this->assertSame(1, $firstPage->currentPage());

        $this->assertCount(1, $lastPage->items(), 'The final page holds the remainder.');
        $this->assertSame(3, $lastPage->currentPage());

        $this->assertEmpty(
            array_intersect(
                $firstPage->getCollection()->pluck('id')->all(),
                $lastPage->getCollection()->pluck('id')->all(),
            ),
            'Pages must not overlap.'
        );
    }

    public function test_for_provider_narrows_to_a_single_client(): void
    {
        $page = $this->repository->forProvider(
            $this->tenant['provider']->id,
            $this->tenant['client']->id,
            15,
            1,
        );

        $this->assertSame(1, $page->total());
        $this->assertSame($this->tenant['document']->id, $page->getCollection()->first()->id);
        $this->assertNotContains(
            $this->tenant['otherDocument']->id,
            $page->getCollection()->pluck('id')->all(),
            "A client must never see a sibling client's document."
        );
    }

    public function test_for_provider_eager_loads_the_uploader_and_matter(): void
    {
        $document = $this->repository->forProvider($this->tenant['provider']->id, null, 15, 1)
            ->getCollection()
            ->first();

        // DocumentResource reads both — without these the table N+1s per row.
        $this->assertTrue($document->relationLoaded('uploader'));
        $this->assertTrue($document->relationLoaded('matter'));
    }

    public function test_for_provider_excludes_archived_documents_by_default(): void
    {
        $archived = $this->documentFor($this->tenant, ['archived_at' => now()]);

        $ids = $this->repository->forProvider($this->tenant['provider']->id, null, 15, 1)
            ->getCollection()
            ->pluck('id')
            ->all();

        $this->assertNotContains($archived->id, $ids);
    }

    public function test_for_provider_archived_true_returns_only_archived_documents(): void
    {
        $archived = $this->documentFor($this->tenant, ['archived_at' => now()]);

        $page = $this->repository->forProvider($this->tenant['provider']->id, null, 15, 1, archived: true);

        $this->assertSame([$archived->id], $page->getCollection()->pluck('id')->all());
    }

    public function test_for_folders_returns_only_documents_in_the_given_folders(): void
    {
        $wanted = $this->folderFor($this->tenant);
        $unwanted = $this->folderFor($this->tenant);

        $inWanted = $this->documentFor($this->tenant, ['folder_id' => $wanted->id]);
        $this->documentFor($this->tenant, ['folder_id' => $unwanted->id]);

        $page = $this->repository->forFolders($this->tenant['provider']->id, [$wanted->id], null, 15, 1);

        $this->assertSame(1, $page->total());
        $this->assertSame($inWanted->id, $page->getCollection()->first()->id);
    }

    public function test_for_folders_accepts_several_folder_ids(): void
    {
        // ListDocumentsAction passes a folder plus every descendant, so the
        // multi-id case is the normal one, not an edge case.
        $parent = $this->folderFor($this->tenant);
        $child = $this->folderFor($this->tenant, ['parent_id' => $parent->id]);

        $this->documentFor($this->tenant, ['folder_id' => $parent->id]);
        $this->documentFor($this->tenant, ['folder_id' => $child->id]);

        $page = $this->repository->forFolders($this->tenant['provider']->id, [$parent->id, $child->id], null, 15, 1);

        $this->assertSame(2, $page->total());
    }

    public function test_for_folders_never_crosses_the_tenant_boundary(): void
    {
        // The other tenant's folder id is passed deliberately: the folder id
        // alone must not be enough to reach its documents.
        $foreignFolder = $this->folderFor($this->otherTenant);
        $this->documentFor($this->otherTenant, ['folder_id' => $foreignFolder->id]);

        $page = $this->repository->forFolders($this->tenant['provider']->id, [$foreignFolder->id], null, 15, 1);

        $this->assertSame(0, $page->total());
    }

    public function test_for_folders_narrows_to_a_single_client(): void
    {
        $folder = $this->folderFor($this->tenant);

        $own = $this->documentFor($this->tenant, [
            'folder_id' => $folder->id,
            'client_id' => $this->tenant['client']->id,
        ]);
        $this->documentFor($this->tenant, [
            'folder_id' => $folder->id,
            'client_id' => $this->tenant['otherClient']->id,
        ]);

        $page = $this->repository->forFolders(
            $this->tenant['provider']->id,
            [$folder->id],
            $this->tenant['client']->id,
            15,
            1,
        );

        $this->assertSame(1, $page->total());
        $this->assertSame($own->id, $page->getCollection()->first()->id);
    }

    public function test_for_folders_eager_loads_the_uploader_and_matter(): void
    {
        $folder = $this->folderFor($this->tenant);
        $this->documentFor($this->tenant, ['folder_id' => $folder->id]);

        $document = $this->repository->forFolders($this->tenant['provider']->id, [$folder->id], null, 15, 1)
            ->getCollection()
            ->first();

        $this->assertTrue($document->relationLoaded('uploader'));
        $this->assertTrue($document->relationLoaded('matter'));
    }

    public function test_total_size_sums_the_providers_documents(): void
    {
        Document::query()->delete();

        $this->documentFor($this->tenant, ['size' => 1_000]);
        $this->documentFor($this->tenant, ['size' => 2_500]);

        $this->assertSame(3_500, $this->repository->totalSizeForProvider($this->tenant['provider']->id));
    }

    public function test_total_size_is_zero_for_a_provider_with_no_documents(): void
    {
        Document::query()->delete();

        // sum() returns null on an empty set — the repository casts, so this
        // is an int 0 rather than null reaching StorageUsage.
        $this->assertSame(0, $this->repository->totalSizeForProvider($this->tenant['provider']->id));
    }

    public function test_total_size_excludes_other_tenants(): void
    {
        Document::query()->delete();

        $this->documentFor($this->tenant, ['size' => 1_000]);
        $this->documentFor($this->otherTenant, ['size' => 9_999_999]);

        $this->assertSame(1_000, $this->repository->totalSizeForProvider($this->tenant['provider']->id));
    }

    public function test_total_size_narrows_to_a_single_client(): void
    {
        Document::query()->delete();

        $this->documentFor($this->tenant, ['size' => 500, 'client_id' => $this->tenant['client']->id]);
        $this->documentFor($this->tenant, ['size' => 700, 'client_id' => $this->tenant['otherClient']->id]);
        $this->documentFor($this->tenant, ['size' => 900, 'client_id' => null]);

        $this->assertSame(
            500,
            $this->repository->totalSizeForProvider($this->tenant['provider']->id, $this->tenant['client']->id),
        );
    }

    public function test_total_size_spans_every_workspace_of_the_provider(): void
    {
        // Storage usage is checked against the plan allowance, which is
        // per-provider — so switching workspace must not change the total.
        Document::query()->acrossWorkspaces()->delete();

        $secondWorkspace = \PactTrackSDK\SharedResources\Modules\Workspace\Models\Workspace::factory()
            ->forProvider($this->tenant['provider'])
            ->create(['name' => 'docrepo-a second']);

        $this->documentFor($this->tenant, ['size' => 1_000]);
        $this->documentFor($this->tenant, [
            'size' => 4_000,
            'workspace_id' => $secondWorkspace->id,
        ]);

        // Stand inside the first workspace: a workspace-scoped query would
        // now only see the 1,000-byte document.
        app(\PactTrackSDK\SharedResources\Modules\Workspace\Domain\Ports\CurrentWorkspace::class)
            ->setId($this->tenant['workspace']->id);

        $this->assertSame(
            5_000,
            $this->repository->totalSizeForProvider($this->tenant['provider']->id),
        );
    }

    private function documentFor(TestScenarioCollection $tenant, array $attributes = []): Document
    {
        return Document::factory()->create(array_merge([
            'provider_id' => $tenant['provider']->id,
            'workspace_id' => $tenant['workspace']->id,
            'uploaded_by' => $tenant['owner']->id,
        ], $attributes));
    }

    private function folderFor(TestScenarioCollection $tenant, array $attributes = []): Folder
    {
        return Folder::factory()->create(array_merge([
            'provider_id' => $tenant['provider']->id,
        ], $attributes));
    }
}
