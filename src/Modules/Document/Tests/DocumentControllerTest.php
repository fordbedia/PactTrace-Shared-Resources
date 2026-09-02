<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Document\Tests;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PactTrackSDK\SharedResources\Modules\Document\Domain\Enums\DocumentStatus;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\Document\Models\Folder;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
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
        // Allowances now come from the Plan enum, not config — Professional is
        // 50 GB. See User\Domain\ValueObjects\Plan::storageLimitBytes().
        $this->tenant['provider']->forceFill(['plan' => 'professional'])->save();

        $limit = \PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Plan::Professional->storageLimitBytes();

        Document::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'uploaded_by' => $this->tenant['owner']->id,
            'size' => 620,
        ]);

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
        Document::query()->delete();

        Document::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'uploaded_by' => $this->tenant['owner']->id,
            'size' => 100,
        ]);
        Document::factory()->create([
            'provider_id' => $this->otherTenant['provider']->id,
            'workspace_id' => $this->otherTenant['workspace']->id,
            'uploaded_by' => $this->otherTenant['owner']->id,
            'size' => 5_000,
        ]);

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
}
