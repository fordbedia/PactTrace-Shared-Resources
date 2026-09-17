<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Document\Tests;

use PactTrackSDK\SharedResources\Modules\Document\Application\DTO\DocumentFilters;
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
        $page = $this->repository->forProvider($this->tenant['provider']->id, new DocumentFilters(), 15, 1);

        $providerIds = $page->getCollection()->pluck('provider_id')->unique()->all();

        $this->assertSame([$this->tenant['provider']->id], $providerIds);
        // The scenario seeds two documents per tenant (one per client).
        $this->assertSame(2, $page->total());
    }

    public function test_for_provider_orders_newest_first(): void
    {
        $older = $this->documentFor($this->tenant, ['created_at' => now()->subDays(3)]);
        $newer = $this->documentFor($this->tenant, ['created_at' => now()->addDay()]);

        $ids = $this->repository->forProvider($this->tenant['provider']->id, new DocumentFilters(), 15, 1)
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

        $firstPage = $this->repository->forProvider($this->tenant['provider']->id, new DocumentFilters(), 2, 1);
        $lastPage = $this->repository->forProvider($this->tenant['provider']->id, new DocumentFilters(), 2, 3);

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
            new DocumentFilters(clientId: $this->tenant['client']->id),
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
        $document = $this->repository->forProvider($this->tenant['provider']->id, new DocumentFilters(), 15, 1)
            ->getCollection()
            ->first();

        // DocumentResource reads both — without these the table N+1s per row.
        $this->assertTrue($document->relationLoaded('uploader'));
        $this->assertTrue($document->relationLoaded('matter'));
    }

    public function test_for_provider_excludes_archived_documents_by_default(): void
    {
        $archived = $this->documentFor($this->tenant, ['archived_at' => now()]);

        $ids = $this->repository->forProvider($this->tenant['provider']->id, new DocumentFilters(), 15, 1)
            ->getCollection()
            ->pluck('id')
            ->all();

        $this->assertNotContains($archived->id, $ids);
    }

    public function test_for_provider_archived_true_returns_only_archived_documents(): void
    {
        $archived = $this->documentFor($this->tenant, ['archived_at' => now()]);

        $page = $this->repository->forProvider($this->tenant['provider']->id, new DocumentFilters(), 15, 1, archived: true);

        $this->assertSame([$archived->id], $page->getCollection()->pluck('id')->all());
    }

    public function test_for_folders_returns_only_documents_in_the_given_folders(): void
    {
        $wanted = $this->folderFor($this->tenant);
        $unwanted = $this->folderFor($this->tenant);

        $inWanted = $this->documentFor($this->tenant, ['folder_id' => $wanted->id]);
        $this->documentFor($this->tenant, ['folder_id' => $unwanted->id]);

        $page = $this->repository->forFolders($this->tenant['provider']->id, [$wanted->id], new DocumentFilters(), 15, 1);

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

        $page = $this->repository->forFolders($this->tenant['provider']->id, [$parent->id, $child->id], new DocumentFilters(), 15, 1);

        $this->assertSame(2, $page->total());
    }

    public function test_for_folders_never_crosses_the_tenant_boundary(): void
    {
        // The other tenant's folder id is passed deliberately: the folder id
        // alone must not be enough to reach its documents.
        $foreignFolder = $this->folderFor($this->otherTenant);
        $this->documentFor($this->otherTenant, ['folder_id' => $foreignFolder->id]);

        $page = $this->repository->forFolders($this->tenant['provider']->id, [$foreignFolder->id], new DocumentFilters(), 15, 1);

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
            new DocumentFilters(clientId: $this->tenant['client']->id),
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

        $document = $this->repository->forFolders($this->tenant['provider']->id, [$folder->id], new DocumentFilters(), 15, 1)
            ->getCollection()
            ->first();

        $this->assertTrue($document->relationLoaded('uploader'));
        $this->assertTrue($document->relationLoaded('matter'));
    }

    public function test_for_provider_narrows_by_matter_id(): void
    {
        $matter = \PactTrackSDK\SharedResources\Modules\Matter\Models\Matter::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
        ]);
        $inMatter = $this->documentFor($this->tenant, ['matter_id' => $matter->id]);
        $this->documentFor($this->tenant);

        $page = $this->repository->forProvider(
            $this->tenant['provider']->id,
            new DocumentFilters(matterId: $matter->id),
            15,
            1,
        );

        $this->assertSame([$inMatter->id], $page->getCollection()->pluck('id')->all());
    }

    public function test_for_provider_narrows_by_file_type(): void
    {
        // The scenario's own tenant document also defaults to 'pdf' (the
        // factory's default file_type) — narrow this assertion to
        // "the txt document is excluded" rather than an exact id list, so it
        // doesn't depend on the scenario's own unrelated fixture rows.
        $txt = $this->documentFor($this->tenant, ['name' => 'notes.txt', 'file_type' => 'txt']);

        $page = $this->repository->forProvider(
            $this->tenant['provider']->id,
            new DocumentFilters(fileTypes: ['pdf']),
            15,
            1,
        );

        $ids = $page->getCollection()->pluck('id')->all();
        $this->assertNotContains($txt->id, $ids);
    }

    public function test_for_provider_narrows_by_multiple_file_types(): void
    {
        $doc = $this->documentFor($this->tenant, ['name' => 'letter.docx', 'file_type' => 'doc']);
        $txt = $this->documentFor($this->tenant, ['name' => 'notes.txt', 'file_type' => 'txt']);

        $page = $this->repository->forProvider(
            $this->tenant['provider']->id,
            new DocumentFilters(fileTypes: ['pdf', 'doc']),
            15,
            1,
        );

        $ids = $page->getCollection()->pluck('id')->all();
        $this->assertContains($doc->id, $ids);
        $this->assertNotContains($txt->id, $ids);
    }

    public function test_for_provider_narrows_by_date_range(): void
    {
        $inRange = $this->documentFor($this->tenant, ['created_at' => '2026-08-15 12:00:00']);
        $this->documentFor($this->tenant, ['created_at' => '2026-07-01 12:00:00']);
        $this->documentFor($this->tenant, ['created_at' => '2026-09-01 12:00:00']);

        $page = $this->repository->forProvider(
            $this->tenant['provider']->id,
            new DocumentFilters(dateFrom: '2026-08-01', dateTo: '2026-08-31'),
            15,
            1,
        );

        $this->assertSame([$inRange->id], $page->getCollection()->pluck('id')->all());
    }

    public function test_for_provider_narrows_by_name_search(): void
    {
        $wanted = $this->documentFor($this->tenant, ['name' => 'Smith Estate Retainer.pdf']);
        $this->documentFor($this->tenant, ['name' => 'Jones NDA.pdf']);

        $page = $this->repository->forProvider(
            $this->tenant['provider']->id,
            new DocumentFilters(search: 'smith'),
            15,
            1,
        );

        $this->assertSame([$wanted->id], $page->getCollection()->pluck('id')->all());
    }

    public function test_for_folders_combines_matter_file_type_and_date_filters_with_the_folder_scope(): void
    {
        $folder = $this->folderFor($this->tenant);
        $matter = \PactTrackSDK\SharedResources\Modules\Matter\Models\Matter::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
        ]);

        $wanted = $this->documentFor($this->tenant, [
            'folder_id' => $folder->id,
            'matter_id' => $matter->id,
            'file_type' => 'pdf',
            'created_at' => '2026-08-15 12:00:00',
        ]);
        // Wrong matter.
        $this->documentFor($this->tenant, [
            'folder_id' => $folder->id,
            'file_type' => 'pdf',
            'created_at' => '2026-08-15 12:00:00',
        ]);
        // Wrong file type.
        $this->documentFor($this->tenant, [
            'folder_id' => $folder->id,
            'matter_id' => $matter->id,
            'file_type' => 'txt',
            'created_at' => '2026-08-15 12:00:00',
        ]);
        // Wrong date.
        $this->documentFor($this->tenant, [
            'folder_id' => $folder->id,
            'matter_id' => $matter->id,
            'file_type' => 'pdf',
            'created_at' => '2026-07-01 12:00:00',
        ]);
        // Right everything, wrong folder.
        $this->documentFor($this->tenant, [
            'matter_id' => $matter->id,
            'file_type' => 'pdf',
            'created_at' => '2026-08-15 12:00:00',
        ]);

        $page = $this->repository->forFolders(
            $this->tenant['provider']->id,
            [$folder->id],
            new DocumentFilters(matterId: $matter->id, fileTypes: ['pdf'], dateFrom: '2026-08-01', dateTo: '2026-08-31'),
            15,
            1,
        );

        $this->assertSame([$wanted->id], $page->getCollection()->pluck('id')->all());
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

    /**
     * Backs the "Documents on this matter" cascade UpdateMattersHandler
     * calls when a matter's own client changes — see
     * .claude/rules/matter.md, "Matter Type and Edit Matter".
     */
    public function test_reassign_client_for_matter_updates_only_that_matters_documents(): void
    {
        $matter = $this->tenant['matter'];
        $newClientId = $this->tenant['otherClient']->id;

        $targeted = $this->documentFor($this->tenant, [
            'matter_id' => $matter->id,
            'client_id' => $this->tenant['client']->id,
        ]);
        $unrelated = $this->documentFor($this->tenant, [
            'matter_id' => $this->tenant['otherMatter']->id,
            'client_id' => $this->tenant['client']->id,
        ]);

        $updated = $this->repository->reassignClientForMatter($matter->id, $newClientId);

        $this->assertSame(2, $updated); // the fixture's own $document, plus $targeted
        $this->assertSame($newClientId, $targeted->fresh()->client_id);
        $this->assertNotSame($newClientId, $unrelated->fresh()->client_id);
    }

    /**
     * A document already at the new client is left alone — the `!=` guard
     * in the repository, asserted here via the returned affected-row count.
     */
    public function test_reassign_client_for_matter_is_a_no_op_when_nothing_disagrees(): void
    {
        $matter = $this->tenant['matter'];

        $updated = $this->repository->reassignClientForMatter($matter->id, $this->tenant['client']->id);

        $this->assertSame(0, $updated);
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
