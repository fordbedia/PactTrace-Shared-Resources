<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Document\Tests;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PactTrackSDK\SharedResources\Modules\Document\Domain\Enums\DocumentStatus;
use PactTrackSDK\SharedResources\Modules\Document\Domain\Ports\DocumentStorage;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\Document\Models\Folder;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\Modules\User\Models\Subscription;
use PactTrackSDK\SharedResources\Modules\User\Models\User;
use PactTrackSDK\SharedResources\TestCase\Extras\LoadsModuleApiRoutes;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The HTTP surface behind the document table, upload modal and STORAGE
 * indicator on /dashboard/documents — GET /api/documents,
 * GET /api/documents/storage, POST /api/documents.
 *
 * Driven over real HTTP rather than by calling the controller's methods:
 * validation (StoreDocumentRequest), the policy gates and the JSON shape the
 * frontend parses are all middleware/framework behaviour that a direct method
 * call would skip, and they are most of what this thin controller is for.
 *
 * Note routes are not auto-loaded under the testing environment (see
 * SharedResourceServiceProvider::loadModules), hence LoadsModuleApiRoutes.
 */
class DocumentControllerTest extends BaseTest
{
    use LoadsModuleApiRoutes;

    private const DISK = 'documents-test';

    private TestScenarioCollection $tenant;

    private TestScenarioCollection $otherTenant;

    protected function moduleApiRoutes(): array
    {
        return [__DIR__ . '/../routes/api.php'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(self::DISK);
        config(['filesystems.document_disk' => self::DISK]);

        $this->tenant = ProviderTenantScenario::make('doc-http-a');
        $this->otherTenant = ProviderTenantScenario::make('doc-http-b');
    }

    /* ── index ─────────────────────────────────────────────────────────── */

    public function test_listing_documents_requires_being_signed_in(): void
    {
        // There is no auth middleware yet, so the controller's own guard is
        // the only thing standing between an anonymous request and the
        // tenant's document list.
        $this->getJson('/api/documents')
            ->assertStatus(401)
            ->assertJsonPath('message', 'You must be signed in to a provider account to view documents.');
    }

    public function test_a_user_with_no_provider_is_refused(): void
    {
        $orphan = User::factory()->create(['provider_id' => null]);

        $this->actingAs($orphan)->getJson('/api/documents')->assertStatus(401);
    }

    public function test_it_lists_the_tenants_documents(): void
    {
        $response = $this->actingAs($this->tenant['owner'])->getJson('/api/documents');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'name', 'mime_type', 'size', 'version', 'folder_id', 'uploaded_by_name']],
                'meta' => ['current_page', 'last_page', 'per_page', 'total', 'from', 'to'],
            ]);

        $this->assertSame(2, $response->json('meta.total'));
        $this->assertEqualsCanonicalizing(
            [$this->tenant['document']->id, $this->tenant['otherDocument']->id],
            $response->json('data.*.id'),
        );
    }

    public function test_it_never_lists_another_tenants_documents(): void
    {
        $ids = $this->actingAs($this->tenant['owner'])->getJson('/api/documents')->json('data.*.id');

        $this->assertNotContains($this->otherTenant['document']->id, $ids);
    }

    public function test_a_client_user_sees_only_their_own_documents(): void
    {
        $response = $this->actingAs($this->tenant['clientUser'])->getJson('/api/documents');

        $response->assertOk();
        $this->assertSame([$this->tenant['document']->id], $response->json('data.*.id'));
        $this->assertSame(1, $response->json('meta.total'));
    }

    public function test_it_paginates(): void
    {
        Document::factory()->count(3)->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'uploaded_by' => $this->tenant['owner']->id,
        ]);

        $response = $this->actingAs($this->tenant['owner'])->getJson('/api/documents?per_page=2&page=2');

        $response->assertOk()
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonCount(2, 'data');
    }

    public function test_per_page_is_clamped(): void
    {
        // Unbounded per_page would defeat the point of paginating a library
        // that grows without limit.
        $this->actingAs($this->tenant['owner'])
            ->getJson('/api/documents?per_page=100000')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);

        $this->actingAs($this->tenant['owner'])
            ->getJson('/api/documents?per_page=0')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 1);
    }

    public function test_a_junk_per_page_falls_back_rather_than_erroring(): void
    {
        $this->actingAs($this->tenant['owner'])
            ->getJson('/api/documents?per_page=lots')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 1);
    }

    public function test_filtering_by_folder_includes_documents_nested_at_any_depth(): void
    {
        $parent = $this->folder('Client Matters');
        $child = $this->folder('NDA', $parent->id);
        $grandchild = $this->folder('2026', $child->id);
        $unrelated = $this->folder('Personal');

        $inParent = $this->documentIn($parent);
        $inGrandchild = $this->documentIn($grandchild);
        $elsewhere = $this->documentIn($unrelated);

        $ids = $this->actingAs($this->tenant['owner'])
            ->getJson("/api/documents?folder_id={$parent->id}")
            ->assertOk()
            ->json('data.*.id');

        $this->assertEqualsCanonicalizing([$inParent->id, $inGrandchild->id], $ids);
        $this->assertNotContains($elsewhere->id, $ids);
    }

    public function test_filtering_by_a_leaf_folder_returns_only_its_own_documents(): void
    {
        $parent = $this->folder('Client Matters');
        $child = $this->folder('NDA', $parent->id);

        $this->documentIn($parent);
        $inChild = $this->documentIn($child);

        $ids = $this->actingAs($this->tenant['owner'])
            ->getJson("/api/documents?folder_id={$child->id}")
            ->json('data.*.id');

        $this->assertSame([$inChild->id], $ids);
    }

    public function test_another_tenants_folder_id_returns_nothing(): void
    {
        $foreign = Folder::factory()->create(['provider_id' => $this->otherTenant['provider']->id]);
        Document::factory()->create([
            'provider_id' => $this->otherTenant['provider']->id,
            'workspace_id' => $this->otherTenant['workspace']->id,
            'uploaded_by' => $this->otherTenant['owner']->id,
            'folder_id' => $foreign->id,
        ]);

        $this->actingAs($this->tenant['owner'])
            ->getJson("/api/documents?folder_id={$foreign->id}")
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    /* ── matter_id (Matter Detail's "Documents on this matter") ──────────
     * See .claude/rules/document.md and .claude/rules/matter.md. */

    public function test_filtering_by_matter_id_returns_only_that_matters_documents(): void
    {
        $inMatter = Document::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'uploaded_by' => $this->tenant['owner']->id,
            'matter_id' => $this->tenant['matter']->id,
        ]);
        $elsewhere = Document::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'uploaded_by' => $this->tenant['owner']->id,
            'matter_id' => $this->tenant['otherMatter']->id,
        ]);

        $ids = $this->actingAs($this->tenant['owner'])
            ->getJson("/api/documents?matter_id={$this->tenant['matter']->id}")
            ->assertOk()
            ->json('data.*.id');

        $this->assertContains($inMatter->id, $ids);
        $this->assertNotContains($elsewhere->id, $ids);
    }

    public function test_another_tenants_matter_id_returns_nothing(): void
    {
        $this->actingAs($this->tenant['owner'])
            ->getJson("/api/documents?matter_id={$this->otherTenant['matter']->id}")
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    /* ── client_id (Client Detail's Documents tab) ────────────────────────
     * See .claude/rules/document.md and .claude/rules/client.md. */

    public function test_filtering_by_client_id_returns_only_that_clients_documents(): void
    {
        $otherClientDocument = Document::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'uploaded_by' => $this->tenant['owner']->id,
            'client_id' => $this->tenant['otherClient']->id,
        ]);

        $ids = $this->actingAs($this->tenant['owner'])
            ->getJson("/api/documents?client_id={$this->tenant['client']->id}")
            ->assertOk()
            ->json('data.*.id');

        $this->assertContains($this->tenant['document']->id, $ids);
        $this->assertNotContains($otherClientDocument->id, $ids);
    }

    public function test_another_tenants_client_id_returns_nothing(): void
    {
        $this->actingAs($this->tenant['owner'])
            ->getJson("/api/documents?client_id={$this->otherTenant['client']->id}")
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    /**
     * A client-portal user's own identity always wins over a submitted
     * `client_id` — the same "derive from the resolved actor, don't trust
     * the request for it" rule this module applies everywhere else. Without
     * this a client could pass `?client_id=<someone else's id>` and read
     * another client's documents.
     */
    public function test_a_client_users_own_scoping_is_not_overridable_via_the_query_string(): void
    {
        $response = $this->actingAs($this->tenant['clientUser'])
            ->getJson("/api/documents?client_id={$this->tenant['otherClient']->id}");

        $response->assertOk();
        $this->assertSame([$this->tenant['document']->id], $response->json('data.*.id'));
    }

    /* ── File Type / Date Range / search filters, combined with folder
     * scope ── see .claude/rules/document.md, "File Type filter". */

    public function test_filtering_by_file_type_narrows_within_the_current_folder(): void
    {
        $folder = $this->folder('Client Matters');
        $pdf = Document::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'uploaded_by' => $this->tenant['owner']->id,
            'folder_id' => $folder->id,
            'name' => 'retainer.pdf',
            'file_type' => 'pdf',
        ]);
        Document::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'uploaded_by' => $this->tenant['owner']->id,
            'folder_id' => $folder->id,
            'name' => 'notes.txt',
            'file_type' => 'txt',
        ]);

        $ids = $this->actingAs($this->tenant['owner'])
            ->getJson("/api/documents?folder_id={$folder->id}&file_type[]=pdf")
            ->assertOk()
            ->json('data.*.id');

        $this->assertSame([$pdf->id], $ids);
    }

    public function test_filtering_by_multiple_file_types(): void
    {
        $doc = Document::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'uploaded_by' => $this->tenant['owner']->id,
            'file_type' => 'doc',
        ]);
        $txt = Document::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'uploaded_by' => $this->tenant['owner']->id,
            'file_type' => 'txt',
        ]);

        $ids = $this->actingAs($this->tenant['owner'])
            ->getJson('/api/documents?file_type[]=doc&file_type[]=txt')
            ->assertOk()
            ->json('data.*.id');

        $this->assertEqualsCanonicalizing([$doc->id, $txt->id], $ids);
    }

    public function test_an_unrecognised_file_type_is_ignored_rather_than_erroring(): void
    {
        $this->actingAs($this->tenant['owner'])
            ->getJson('/api/documents?file_type[]=exe')
            ->assertOk();
    }

    public function test_filtering_by_date_range(): void
    {
        $inRange = Document::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'uploaded_by' => $this->tenant['owner']->id,
            'created_at' => '2026-08-15 12:00:00',
        ]);
        $outOfRange = Document::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'uploaded_by' => $this->tenant['owner']->id,
            'created_at' => '2026-07-01 12:00:00',
        ]);

        $ids = $this->actingAs($this->tenant['owner'])
            ->getJson('/api/documents?date_from=2026-08-01&date_to=2026-08-31')
            ->assertOk()
            ->json('data.*.id');

        $this->assertContains($inRange->id, $ids);
        $this->assertNotContains($outOfRange->id, $ids);
    }

    public function test_an_invalid_date_is_ignored_rather_than_erroring(): void
    {
        $this->actingAs($this->tenant['owner'])
            ->getJson('/api/documents?date_from=not-a-date')
            ->assertOk();
    }

    public function test_filtering_by_name_search(): void
    {
        $wanted = Document::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'uploaded_by' => $this->tenant['owner']->id,
            'name' => 'Smith Estate Retainer.pdf',
        ]);
        Document::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'uploaded_by' => $this->tenant['owner']->id,
            'name' => 'Jones NDA.pdf',
        ]);

        $ids = $this->actingAs($this->tenant['owner'])
            ->getJson('/api/documents?search=smith')
            ->assertOk()
            ->json('data.*.id');

        $this->assertSame([$wanted->id], $ids);
    }

    /**
     * Every filter combines with folder scope and each other — the whole
     * point of Part 1's refactor away from `matter_id` being a mutually
     * exclusive alternate path. See ListDocumentsAction.
     */
    public function test_folder_matter_file_type_and_date_filters_all_combine(): void
    {
        $folder = $this->folder('Client Matters');
        $matter = $this->tenant['matter'];

        $wanted = Document::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'uploaded_by' => $this->tenant['owner']->id,
            'folder_id' => $folder->id,
            'matter_id' => $matter->id,
            'file_type' => 'pdf',
            'created_at' => '2026-08-15 12:00:00',
        ]);

        // Same folder and matter, wrong file type — excluded.
        Document::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'uploaded_by' => $this->tenant['owner']->id,
            'folder_id' => $folder->id,
            'matter_id' => $matter->id,
            'file_type' => 'txt',
            'created_at' => '2026-08-15 12:00:00',
        ]);

        // Right everything except it's in a different folder — excluded.
        Document::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'uploaded_by' => $this->tenant['owner']->id,
            'matter_id' => $matter->id,
            'file_type' => 'pdf',
            'created_at' => '2026-08-15 12:00:00',
        ]);

        $params = http_build_query([
            'folder_id' => $folder->id,
            'matter_id' => $matter->id,
            'file_type' => ['pdf'],
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-31',
        ]);

        $ids = $this->actingAs($this->tenant['owner'])
            ->getJson("/api/documents?{$params}")
            ->assertOk()
            ->json('data.*.id');

        $this->assertSame([$wanted->id], $ids);
    }

    /**
     * A `matter_id`-only request (no folder_id, no other new filter) must
     * keep behaving exactly as it always has — the Matter Detail page's
     * "Documents on this matter" section sends exactly this shape. See
     * ListDocumentsAction's own docblock.
     */
    public function test_matter_id_alone_still_routes_to_the_legacy_flat_matter_listing(): void
    {
        $inMatter = Document::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'uploaded_by' => $this->tenant['owner']->id,
            'matter_id' => $this->tenant['matter']->id,
            'folder_id' => null,
        ]);

        $ids = $this->actingAs($this->tenant['owner'])
            ->getJson("/api/documents?matter_id={$this->tenant['matter']->id}")
            ->assertOk()
            ->json('data.*.id');

        $this->assertContains($inMatter->id, $ids);
    }

    /**
     * An archived document only ever appears when `archived=1`, regardless
     * of which other filters are combined with it.
     */
    public function test_archived_filter_still_applies_alongside_other_filters(): void
    {
        $folder = $this->folder('Client Matters');
        $archived = Document::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'uploaded_by' => $this->tenant['owner']->id,
            'folder_id' => $folder->id,
            'file_type' => 'pdf',
            'archived_at' => now(),
        ]);
        $active = Document::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'uploaded_by' => $this->tenant['owner']->id,
            'folder_id' => $folder->id,
            'file_type' => 'pdf',
        ]);

        $activeIds = $this->actingAs($this->tenant['owner'])
            ->getJson("/api/documents?folder_id={$folder->id}&file_type[]=pdf")
            ->assertOk()
            ->json('data.*.id');
        $this->assertSame([$active->id], $activeIds);

        $archivedIds = $this->actingAs($this->tenant['owner'])
            ->getJson("/api/documents?folder_id={$folder->id}&file_type[]=pdf&archived=1")
            ->assertOk()
            ->json('data.*.id');
        $this->assertSame([$archived->id], $archivedIds);
    }

    public function test_a_document_with_an_envelope_exposes_its_public_id(): void
    {
        $document = Document::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'uploaded_by' => $this->tenant['owner']->id,
            'matter_id' => $this->tenant['matter']->id,
        ]);
        $envelope = Envelope::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'document_id' => $document->id,
        ]);

        $response = $this->actingAs($this->tenant['owner'])
            ->getJson("/api/documents?matter_id={$this->tenant['matter']->id}")
            ->assertOk();

        $row = collect($response->json('data'))->firstWhere('id', $document->id);
        $this->assertSame($envelope->public_id, $row['envelope_public_id']);
    }

    public function test_a_document_with_no_envelope_has_a_null_envelope_public_id(): void
    {
        $document = Document::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'uploaded_by' => $this->tenant['owner']->id,
            'matter_id' => $this->tenant['matter']->id,
        ]);

        $response = $this->actingAs($this->tenant['owner'])
            ->getJson("/api/documents?matter_id={$this->tenant['matter']->id}")
            ->assertOk();

        $row = collect($response->json('data'))->firstWhere('id', $document->id);
        $this->assertNull($row['envelope_public_id']);
    }

    public function test_the_default_listing_excludes_archived_documents(): void
    {
        $active = $this->tenant['document'];
        $archived = $this->documentIn($this->folder('Archive Bucket'));
        $archived->forceFill(['archived_at' => now()])->save();

        $ids = $this->actingAs($this->tenant['owner'])->getJson('/api/documents')->json('data.*.id');

        $this->assertContains($active->id, $ids);
        $this->assertNotContains($archived->id, $ids);
    }

    public function test_the_archived_filter_returns_only_archived_documents(): void
    {
        $active = $this->tenant['document'];
        $archived = $this->documentIn($this->folder('Archive Bucket'));
        $archived->forceFill(['archived_at' => now()])->save();

        $ids = $this->actingAs($this->tenant['owner'])->getJson('/api/documents?archived=1')->json('data.*.id');

        $this->assertSame([$archived->id], $ids);
        $this->assertNotContains($active->id, $ids);
    }

    public function test_the_archived_filter_does_not_surface_soft_deleted_documents(): void
    {
        // archived_at and deleted_at are independent columns/scopes — a
        // soft-deleted document must stay hidden by Eloquent's own
        // SoftDeletes global scope even when explicitly asking for the
        // archived view. See .claude/rules/document.md, "Archival policy".
        $archived = $this->documentIn($this->folder('Archive Bucket'));
        $archived->forceFill(['archived_at' => now()])->save();
        $archived->delete();

        $ids = $this->actingAs($this->tenant['owner'])->getJson('/api/documents?archived=1')->json('data.*.id');

        $this->assertNotContains($archived->id, $ids);
    }

    /* ── storage ───────────────────────────────────────────────────────── */

    public function test_storage_usage_requires_being_signed_in(): void
    {
        $this->getJson('/api/documents/storage')
            ->assertStatus(401)
            ->assertJsonPath('message', 'You must be signed in to a provider account to view storage usage.');
    }

    public function test_it_reports_storage_usage(): void
    {
        Document::query()->delete();
        // Allowances now come from PlanInfo, not config — Professional is
        // 50 GB. See User\Domain\ValueObjects\PlanInfo.
        // The provider-wide "used" figure is the cached
        // `providers.storage_used_bytes` column now, not a live document sum —
        // seed it the way an upload would (see ProviderStorageLedger).
        $this->tenant['provider']->forceFill([
            'plan' => 'professional',
            'storage_used_bytes' => 620,
        ])->save();

        $limit = \PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Plan::Professional->info()->storageLimitBytes;

        $this->actingAs($this->tenant['owner']->fresh())
            ->getJson('/api/documents/storage')
            ->assertOk()
            ->assertJson([
                'used_bytes' => 620,
                'limit_bytes' => $limit,
                'remaining_bytes' => $limit - 620,
                'percentage' => 0.0,
                'over_limit' => false,
                'used_label' => '620 B',
                'limit_label' => '50 GB',
            ]);
    }

    public function test_storage_usage_is_zero_for_an_empty_tenant(): void
    {
        Document::query()->delete();

        $this->actingAs($this->tenant['owner'])
            ->getJson('/api/documents/storage')
            ->assertOk()
            ->assertJsonPath('used_bytes', 0)
            ->assertJsonPath('percentage', 0);
    }

    public function test_storage_usage_excludes_other_tenants(): void
    {
        // Provider-wide "used" is each provider's own cached column — a value
        // ProviderStorageLedger maintains per provider, so cross-tenant
        // isolation is structural.
        $this->tenant['provider']->forceFill(['storage_used_bytes' => 100])->save();
        $this->otherTenant['provider']->forceFill(['storage_used_bytes' => 5_000])->save();

        $this->actingAs($this->tenant['owner'])
            ->getJson('/api/documents/storage')
            ->assertJsonPath('used_bytes', 100);
    }

    public function test_a_client_user_sees_only_their_own_consumption(): void
    {
        Document::query()->delete();

        Document::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'uploaded_by' => $this->tenant['owner']->id,
            'client_id' => $this->tenant['client']->id,
            'size' => 100,
        ]);
        Document::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'uploaded_by' => $this->tenant['owner']->id,
            'client_id' => $this->tenant['otherClient']->id,
            'size' => 900,
        ]);

        $this->actingAs($this->tenant['clientUser'])
            ->getJson('/api/documents/storage')
            ->assertOk()
            ->assertJsonPath('used_bytes', 100);
    }

    /* ── store ─────────────────────────────────────────────────────────── */

    public function test_uploading_requires_being_signed_in(): void
    {
        $this->postJson('/api/documents', ['file' => UploadedFile::fake()->create('a.pdf', 10)])
            ->assertStatus(401);
    }

    public function test_it_uploads_a_document(): void
    {
        $response = $this->actingAs($this->tenant['owner'])->postJson('/api/documents', [
            'file' => UploadedFile::fake()->create('retainer.pdf', 12),
        ]);

        $response->assertSuccessful()
            ->assertJsonPath('data.name', 'retainer.pdf')
            ->assertJsonPath('data.version', 1);

        $document = Document::query()->findOrFail($response->json('data.id'));

        $this->assertSame($this->tenant['provider']->id, $document->provider_id);
        $this->assertSame($this->tenant['owner']->id, $document->uploaded_by);
        $this->assertSame('retainer.pdf', $document->name);

        // The bytes actually landed on the configured disk, under the
        // provider-namespaced key DocumentUploadService builds.
        Storage::disk(self::DISK)->assertExists($document->s3_path);
        $this->assertStringStartsWith("documents/{$this->tenant['provider']->id}/", $document->s3_path);
    }

    /**
     * PlanPolicy gate — see .claude/rules/plan.md. ProviderTenantScenario's
     * default tenant is deliberately on a healthy plan/subscription so
     * unrelated tests never trip this; this flips it back to exercise the
     * gate directly.
     */
    public function test_uploading_is_denied_when_the_subscription_is_not_active(): void
    {
        Subscription::query()->where('provider_id', $this->tenant['provider']->id)->update(['status' => 'expired']);

        $response = $this->actingAs($this->tenant['owner'])->postJson('/api/documents', [
            'file' => UploadedFile::fake()->create('blocked.pdf', 10),
        ]);

        $response->assertStatus(403)->assertJsonPath('reason', 'subscription_inactive');
        $this->assertDatabaseMissing('documents', ['name' => 'blocked.pdf']);
    }

    public function test_it_files_the_upload_into_the_focused_folder(): void
    {
        $folder = $this->folder('NDA');

        $response = $this->actingAs($this->tenant['owner'])->postJson('/api/documents', [
            'file' => UploadedFile::fake()->create('nda.pdf', 4),
            'folder_id' => $folder->id,
        ]);

        $this->assertSame($folder->id, $response->json('data.folder_id'));
    }

    public function test_it_files_the_upload_against_a_matter_and_client(): void
    {
        $response = $this->actingAs($this->tenant['owner'])->postJson('/api/documents', [
            'file' => UploadedFile::fake()->create('brief.pdf', 4),
            'matter_id' => $this->tenant['matter']->id,
            'client_id' => $this->tenant['client']->id,
        ]);

        $response->assertSuccessful()
            ->assertJsonPath('data.matter_id', $this->tenant['matter']->id)
            ->assertJsonPath('data.client_id', $this->tenant['client']->id);
    }

    /**
     * The upload-success modal on /dashboard/documents links straight to
     * `/dashboard/matters/{public_id}` (see .claude/rules/matter.md) from
     * this very response — matter_public_id has to be on the wire for a
     * matter-attached upload, not fetched separately.
     */
    public function test_uploading_against_a_matter_exposes_the_matters_public_id(): void
    {
        $response = $this->actingAs($this->tenant['owner'])->postJson('/api/documents', [
            'file' => UploadedFile::fake()->create('brief.pdf', 4),
            'matter_id' => $this->tenant['matter']->id,
            'client_id' => $this->tenant['client']->id,
        ]);

        $response->assertSuccessful()
            ->assertJsonPath('data.matter_public_id', $this->tenant['matter']->public_id);
    }

    public function test_a_matters_own_client_wins_over_a_disagreeing_submitted_client_id(): void
    {
        // The frontend keeps these in sync (auto-fills and locks the Client
        // field once a matter is picked — see
        // frontend/app/dashboard/documents/page.js, handleSelectMatter), but
        // the server must not trust a client-submitted client_id that
        // disagrees with the selected matter's own client — a stale page or
        // a non-frontend API caller could still send a mismatched pair. See
        // .claude/rules/document.md.
        $response = $this->actingAs($this->tenant['owner'])->postJson('/api/documents', [
            'file' => UploadedFile::fake()->create('brief.pdf', 4),
            'matter_id' => $this->tenant['matter']->id,
            'client_id' => $this->tenant['otherClient']->id,
        ]);

        $response->assertSuccessful()
            ->assertJsonPath('data.matter_id', $this->tenant['matter']->id)
            ->assertJsonPath('data.client_id', $this->tenant['client']->id);

        $document = Document::query()->findOrFail($response->json('data.id'));
        $this->assertSame($this->tenant['client']->id, $document->client_id);
    }

    public function test_it_respects_an_independently_submitted_client_id_when_no_matter_is_given(): void
    {
        // Document belongsTo Matter is nullable (.claude/rules/matter.md) —
        // "file this document under a client directly, no matter" is a
        // legitimate, supported case, so client_id must still be honoured
        // when matter_id is absent.
        $response = $this->actingAs($this->tenant['owner'])->postJson('/api/documents', [
            'file' => UploadedFile::fake()->create('id.pdf', 4),
            'client_id' => $this->tenant['client']->id,
        ]);

        $response->assertSuccessful()
            ->assertJsonPath('data.matter_id', null)
            ->assertJsonPath('data.client_id', $this->tenant['client']->id);
    }

    public function test_it_rejects_an_upload_with_no_file(): void
    {
        $this->actingAs($this->tenant['owner'])
            ->postJson('/api/documents', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    public function test_it_rejects_a_disallowed_file_type(): void
    {
        $this->actingAs($this->tenant['owner'])
            ->postJson('/api/documents', ['file' => UploadedFile::fake()->create('payload.exe', 4)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    /**
     * Every extension DocuSign's eSignature API accepts as a source document
     * — PactTrack's own upload validation must not be any stricter than
     * that, since these are the same files that later get sent to DocuSign
     * for signature (see .claude/rules/document.md and
     * .claude/rules/signature.md). `.wpd`, `.xps` and `.msg` are singled out
     * in StoreDocumentRequest's own docblock as extensions that a
     * content-sniffing `mimes:` rule can silently reject depending on the
     * host's MIME database — this data provider is what actually proves the
     * chosen `extensions:` rule accepts all of them, not just the
     * commonly-recognised ones.
     */
    #[DataProvider('docusignSupportedExtensions')]
    public function test_it_accepts_every_docusign_supported_extension(string $extension): void
    {
        $response = $this->actingAs($this->tenant['owner'])->postJson('/api/documents', [
            'file' => UploadedFile::fake()->create("document.{$extension}", 4),
        ]);

        $response->assertSuccessful()->assertJsonPath('data.name', "document.{$extension}");
    }

    /**
     * @return array<string, array{string}>
     */
    public static function docusignSupportedExtensions(): array
    {
        $extensions = [
            'doc', 'docm', 'docx', 'dot', 'dotm', 'dotx',
            'htm', 'html', 'msg', 'pdf', 'rtf', 'txt', 'wpd', 'xhtml', 'xps',
        ];

        return array_combine($extensions, array_map(fn (string $ext) => [$ext], $extensions));
    }

    public function test_it_rejects_a_file_over_the_size_limit(): void
    {
        $this->actingAs($this->tenant['owner'])
            ->postJson('/api/documents', ['file' => UploadedFile::fake()->create('huge.pdf', 51_201)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    public function test_it_rejects_a_matter_that_does_not_exist(): void
    {
        $this->actingAs($this->tenant['owner'])
            ->postJson('/api/documents', [
                'file' => UploadedFile::fake()->create('a.pdf', 4),
                'matter_id' => 999_999,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('matter_id');
    }

    public function test_it_refuses_to_file_a_document_against_another_tenants_matter(): void
    {
        // The matter exists, so validation passes — only DocumentPolicy's
        // tenant check stands between this request and cross-tenant filing.
        $this->actingAs($this->tenant['owner'])
            ->postJson('/api/documents', [
                'file' => UploadedFile::fake()->create('a.pdf', 4),
                'matter_id' => $this->otherTenant['matter']->id,
            ])
            ->assertStatus(403);

        $this->assertDatabaseMissing('documents', ['name' => 'a.pdf']);
    }

    public function test_nothing_is_stored_when_authorisation_fails(): void
    {
        $this->actingAs($this->tenant['owner'])
            ->postJson('/api/documents', [
                'file' => UploadedFile::fake()->create('leaked.pdf', 4),
                'matter_id' => $this->otherTenant['matter']->id,
            ])
            ->assertStatus(403);

        $this->assertEmpty(Storage::disk(self::DISK)->allFiles());
    }

    /* ── destroy ───────────────────────────────────────────────────────── */

    public function test_deleting_requires_being_signed_in(): void
    {
        $this->deleteJson("/api/documents/{$this->tenant['document']->id}")->assertStatus(401);
    }

    public function test_it_deletes_a_draft_document(): void
    {
        $document = $this->documentWithStatus(DocumentStatus::Draft);

        $this->actingAs($this->tenant['owner'])
            ->deleteJson("/api/documents/{$document->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('documents', ['id' => $document->id]);
    }

    public function test_it_refuses_to_delete_a_sent_document_over_http(): void
    {
        // A stale page/replayed request for a document that has since moved
        // past draft must get a clear 422, not a 500 — see
        // .claude/rules/document.md, "Deletion policy".
        $document = $this->documentWithStatus(DocumentStatus::Sent);

        $this->actingAs($this->tenant['owner'])
            ->deleteJson("/api/documents/{$document->id}")
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'Document cannot be deleted while its status is "sent". Only a document with status "draft" may be deleted — cancel an in-flight document with Void instead.'
            );

        $this->assertDatabaseHas('documents', ['id' => $document->id, 'deleted_at' => null]);
    }

    public function test_it_refuses_to_delete_a_completed_document_over_http(): void
    {
        $document = $this->documentWithStatus(DocumentStatus::Completed);

        $this->actingAs($this->tenant['owner'])
            ->deleteJson("/api/documents/{$document->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('documents', ['id' => $document->id, 'deleted_at' => null]);
    }

    public function test_deleting_another_tenants_document_is_refused(): void
    {
        $foreign = $this->documentWithStatus(DocumentStatus::Draft, $this->otherTenant);

        $this->actingAs($this->tenant['owner'])
            ->deleteJson("/api/documents/{$foreign->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('documents', ['id' => $foreign->id, 'deleted_at' => null]);
    }

    /* ── archive / unarchive ───────────────────────────────────────────── */

    public function test_archiving_requires_being_signed_in(): void
    {
        $this->postJson("/api/documents/{$this->tenant['document']->id}/archive")->assertStatus(401);
    }

    #[DataProvider('everyStatus')]
    public function test_it_archives_a_document_regardless_of_status(DocumentStatus $status): void
    {
        $document = $this->documentWithStatus($status);

        $this->actingAs($this->tenant['owner'])
            ->postJson("/api/documents/{$document->id}/archive")
            ->assertOk()
            ->assertJsonPath('data.status', $status->value);

        $this->assertNotNull($document->fresh()->archived_at);
    }

    public function test_it_unarchives_a_document(): void
    {
        $document = $this->documentWithStatus(DocumentStatus::Draft);
        $document->forceFill(['archived_at' => now()])->save();

        $this->actingAs($this->tenant['owner'])
            ->postJson("/api/documents/{$document->id}/unarchive")
            ->assertOk()
            ->assertJsonPath('data.archived_at', null);

        $this->assertNull($document->fresh()->archived_at);
    }

    public function test_archiving_another_tenants_document_is_refused(): void
    {
        $foreign = $this->documentWithStatus(DocumentStatus::Draft, $this->otherTenant);

        $this->actingAs($this->tenant['owner'])
            ->postJson("/api/documents/{$foreign->id}/archive")
            ->assertStatus(403);

        $this->assertNull($foreign->fresh()->archived_at);
    }

    /* ── void ──────────────────────────────────────────────────────────── */

    public function test_voiding_requires_being_signed_in(): void
    {
        $this->postJson("/api/documents/{$this->tenant['document']->id}/void")->assertStatus(401);
    }

    public function test_it_voids_a_sent_document(): void
    {
        $document = $this->documentWithStatus(DocumentStatus::Sent);

        $this->actingAs($this->tenant['owner'])
            ->postJson("/api/documents/{$document->id}/void")
            ->assertOk()
            ->assertJsonPath('data.status', 'voided');
    }

    public function test_it_voids_a_partially_signed_document(): void
    {
        $document = $this->documentWithStatus(DocumentStatus::PartiallySigned);

        $this->actingAs($this->tenant['owner'])
            ->postJson("/api/documents/{$document->id}/void")
            ->assertOk()
            ->assertJsonPath('data.status', 'voided');
    }

    public function test_it_refuses_to_void_a_draft_document_over_http(): void
    {
        $document = $this->documentWithStatus(DocumentStatus::Draft);

        $this->actingAs($this->tenant['owner'])
            ->postJson("/api/documents/{$document->id}/void")
            ->assertStatus(422);

        $this->assertSame(DocumentStatus::Draft, $document->fresh()->status);
    }

    public function test_it_refuses_to_void_a_completed_document_over_http(): void
    {
        $document = $this->documentWithStatus(DocumentStatus::Completed);

        $this->actingAs($this->tenant['owner'])
            ->postJson("/api/documents/{$document->id}/void")
            ->assertStatus(422);
    }

    /* ── reassign matter (pen icon) ───────────────────────────────────────
     * The Documents page's per-row "Reassign Matter" action — see
     * .claude/rules/document.md, "Reassign Matter from the Documents page".
     * Changes only document.matter_id (and, whenever a real matter is
     * given, the derived client_id/workspace_id) — never the Matter
     * entity's own fields. */

    public function test_reassigning_matter_requires_being_signed_in(): void
    {
        $this->patchJson("/api/documents/{$this->tenant['document']->id}/matter", ['matter_id' => $this->tenant['otherMatter']->id])
            ->assertStatus(401);
    }

    public function test_it_reassigns_a_documents_matter_and_derives_the_new_matters_client(): void
    {
        $document = $this->tenant['document'];
        $this->assertSame($this->tenant['matter']->id, $document->matter_id);
        $this->assertSame($this->tenant['client']->id, $document->client_id);

        $response = $this->actingAs($this->tenant['owner'])
            ->patchJson("/api/documents/{$document->id}/matter", ['matter_id' => $this->tenant['otherMatter']->id])
            ->assertOk();

        $response->assertJsonPath('data.matter_id', $this->tenant['otherMatter']->id);
        $response->assertJsonPath('data.client_id', $this->tenant['otherClient']->id);

        $document->refresh();
        $this->assertSame($this->tenant['otherMatter']->id, $document->matter_id);
        // The client was never independently submitted — it's derived from
        // the new matter, the same "never trust an independently-submitted
        // value once a parent is present" rule upload already enforces.
        $this->assertSame($this->tenant['otherClient']->id, $document->client_id);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'document.matter_reassigned',
            'auditable_type' => Document::class,
            'auditable_id' => $document->id,
        ]);
    }

    public function test_it_unfiles_a_document_by_reassigning_to_no_matter(): void
    {
        $document = $this->tenant['document'];
        $originalClientId = $document->client_id;

        $this->actingAs($this->tenant['owner'])
            ->patchJson("/api/documents/{$document->id}/matter", ['matter_id' => null])
            ->assertOk()
            ->assertJsonPath('data.matter_id', null);

        $document->refresh();
        $this->assertNull($document->matter_id);
        // Unfiling leaves the client as-is — "client, no matter" is a real,
        // supported state elsewhere in this module, and discarding a
        // document's client just because its matter was cleared would be
        // needlessly destructive.
        $this->assertSame($originalClientId, $document->client_id);
    }

    public function test_reassigning_to_another_tenants_matter_is_rejected(): void
    {
        $document = $this->tenant['document'];

        $this->actingAs($this->tenant['owner'])
            ->patchJson("/api/documents/{$document->id}/matter", ['matter_id' => $this->otherTenant['matter']->id])
            ->assertStatus(422);

        $this->assertSame($this->tenant['matter']->id, $document->fresh()->matter_id);
    }

    public function test_reassigning_another_tenants_document_is_refused(): void
    {
        $foreign = $this->otherTenant['document'];

        $this->actingAs($this->tenant['owner'])
            ->patchJson("/api/documents/{$foreign->id}/matter", ['matter_id' => $this->tenant['otherMatter']->id])
            ->assertStatus(403);

        $this->assertNotNull($foreign->fresh()->matter_id);
        $this->assertNotEquals($this->tenant['otherMatter']->id, $foreign->fresh()->matter_id);
    }

    /**
     * @return array<string, array{DocumentStatus}>
     */
    public static function everyStatus(): array
    {
        return array_combine(
            array_map(fn (DocumentStatus $s) => $s->value, DocumentStatus::cases()),
            array_map(fn (DocumentStatus $s) => [$s], DocumentStatus::cases()),
        );
    }

    private function documentWithStatus(DocumentStatus $status, ?TestScenarioCollection $tenant = null): Document
    {
        $tenant ??= $this->tenant;

        return Document::factory()->create([
            'provider_id' => $tenant['provider']->id,
            'workspace_id' => $tenant['workspace']->id,
            'uploaded_by' => $tenant['owner']->id,
            'status' => $status,
        ]);
    }

    private function folder(string $name, ?int $parentId = null): Folder
    {
        return Folder::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'name' => $name,
            'parent_id' => $parentId,
        ]);
    }

    private function documentIn(Folder $folder): Document
    {
        return Document::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'uploaded_by' => $this->tenant['owner']->id,
            'folder_id' => $folder->id,
        ]);
    }

    /* ── show ──────────────────────────────────────────────────────────── */

    public function test_showing_a_document_requires_being_signed_in(): void
    {
        $this->getJson("/api/documents/{$this->tenant['document']->id}")->assertStatus(401);
    }

    public function test_it_shows_a_documents_full_detail(): void
    {
        // ProviderTenantScenario's fixture document already has an envelope
        // attached — asserting on it here proves show() actually eager-loads
        // `envelopes` (envelope_public_id/envelope_status are whenLoaded on
        // DocumentResource, see .claude/rules/document.md).
        $document = $this->tenant['document'];
        $envelope = $this->tenant['envelope'];

        $response = $this->actingAs($this->tenant['owner'])
            ->getJson("/api/documents/{$document->id}")
            ->assertOk();

        $response->assertJsonPath('data.id', $document->id)
            ->assertJsonPath('data.matter_public_id', $document->matter->public_id)
            ->assertJsonPath('data.client_name', $document->client?->name)
            ->assertJsonPath('data.envelope_public_id', $envelope->public_id)
            ->assertJsonPath('data.envelope_status', $envelope->status->value);
    }

    public function test_showing_another_tenants_document_is_refused(): void
    {
        $foreign = $this->documentWithStatus(DocumentStatus::Draft, $this->otherTenant);

        $this->actingAs($this->tenant['owner'])
            ->getJson("/api/documents/{$foreign->id}")
            ->assertStatus(403);
    }

    /* ── download ──────────────────────────────────────────────────────── */

    public function test_downloading_a_document_requires_being_signed_in(): void
    {
        $this->getJson("/api/documents/{$this->tenant['document']->id}/download")->assertStatus(401);
    }

    /**
     * `Storage::fake()` deliberately wires a `buildTemporaryUrlsUsing`
     * callback (Laravel's own testing convenience), so it produces a real
     * (fabricated) temporary URL rather than throwing the way a genuine
     * `local`-driver disk with no such callback configured would — the
     * controller redirects to it, same as the real S3 adapter in
     * production. See .claude/rules/document.md, "Document download".
     */
    public function test_a_completed_document_can_be_downloaded_via_a_presigned_redirect(): void
    {
        $document = $this->documentWithStatus(DocumentStatus::Completed);
        Storage::disk(self::DISK)->put($document->s3_path, 'the-file-bytes');

        $response = $this->actingAs($this->tenant['owner'])
            ->get("/api/documents/{$document->id}/download");

        $response->assertRedirect();
        $this->assertStringContainsString($document->s3_path, (string) $response->headers->get('Location'));

        $this->assertDatabaseHas('audit_logs', [
            'provider_id' => $document->provider_id,
            'user_id' => $this->tenant['owner']->id,
            'action' => 'document.downloaded',
            'auditable_type' => Document::class,
            'auditable_id' => $document->id,
        ]);
    }

    /**
     * The `local` dev-disk fallback path — exercised here by rebinding
     * `DocumentStorage` to a fake that has no temporary-URL support at all
     * (mirrors a real `local`-driver disk with no `temporaryUrlCallback`
     * configured), same "rebind the port locally to simulate a specific
     * provider" pattern the Signature module's own tests use. See
     * .claude/rules/document.md, "Document download".
     */
    public function test_download_falls_back_to_streaming_when_the_disk_has_no_temporary_url_support(): void
    {
        $document = $this->documentWithStatus(DocumentStatus::Completed);

        $this->app->bind(DocumentStorage::class, fn () => new class implements DocumentStorage {
            public function put(string $path, string $contents): void
            {
            }

            public function delete(string $path): void
            {
            }

            public function exists(string $path): bool
            {
                return true;
            }

            public function get(string $path): string
            {
                return 'the-file-bytes';
            }

            public function temporaryUrl(string $path, \DateTimeInterface $expiresAt): ?string
            {
                return null;
            }
        });

        $response = $this->actingAs($this->tenant['owner'])
            ->get("/api/documents/{$document->id}/download");

        $response->assertOk();
        $this->assertSame('the-file-bytes', $response->streamedContent());
    }

    public function test_downloading_another_tenants_document_is_refused(): void
    {
        $foreign = $this->documentWithStatus(DocumentStatus::Draft, $this->otherTenant);

        $this->actingAs($this->tenant['owner'])
            ->get("/api/documents/{$foreign->id}/download")
            ->assertStatus(403);
    }
}
