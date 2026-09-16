<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Document\Tests;

use Illuminate\Support\Facades\Storage;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\Document\Models\Folder;
use PactTrackSDK\SharedResources\TestCase\Extras\LoadsModuleApiRoutes;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/**
 * The Documents page's bulk bar — POST /api/documents/move-many,
 * /archive-many and /zip. See .claude/rules/document.md.
 *
 * The load-bearing assertions here are the isolation ones. All three
 * endpoints take a bare list of `document_ids` from the request body, and
 * `DocumentController::authorizedDocuments()` leans entirely on a per-row
 * `Gate::authorize()` (via DocumentPolicy → TenantScopedPolicy) to reject a
 * foreign id — the query that loads them has no `provider_id` filter of its
 * own. If that gate ever stopped failing closed, one tenant could move,
 * archive or download another tenant's documents in bulk, and nothing else
 * in the stack would catch it. The `*_another_tenants_document_*` tests are
 * those regression guards.
 *
 * `move-many` additionally has to reject a foreign *destination* folder,
 * which no document-level gate can express: `MoveDocumentsRequest` only
 * validates `exists:folders,id`, so the controller resolves the folder
 * inside the tenant itself.
 */
class BulkDocumentActionsTest extends BaseTest
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

        $this->tenant = ProviderTenantScenario::make('doc-bulk-a');
        $this->otherTenant = ProviderTenantScenario::make('doc-bulk-b');
    }

    /** A document of the acting tenant, with real bytes on the fake disk. */
    private function document(array $attributes = []): Document
    {
        $document = Document::factory()->create(array_merge([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'matter_id' => $this->tenant['matter']->id,
            'uploaded_by' => $this->tenant['owner']->id,
        ], $attributes));

        Storage::disk(self::DISK)->put($document->s3_path, "bytes for {$document->name}");

        return $document;
    }

    private function folder(array $attributes = []): Folder
    {
        return Folder::factory()->create(array_merge([
            'provider_id' => $this->tenant['provider']->id,
        ], $attributes));
    }

    /* ── move-many ─────────────────────────────────────────────────────── */

    public function test_moving_documents_requires_being_signed_in(): void
    {
        $this->postJson('/api/documents/move-many', [
            'document_ids' => [$this->document()->id],
            'folder_id' => $this->folder()->id,
        ])->assertStatus(401);
    }

    public function test_it_moves_every_selected_document_into_the_target_folder(): void
    {
        $origin = $this->folder(['name' => 'Origin']);
        $target = $this->folder(['name' => 'Target']);

        $first = $this->document(['folder_id' => $origin->id]);
        $second = $this->document(['folder_id' => null]);

        $this->actingAs($this->tenant['owner'])
            ->postJson('/api/documents/move-many', [
                'document_ids' => [$first->id, $second->id],
                'folder_id' => $target->id,
            ])
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->assertSame($target->id, $first->fresh()->folder_id);
        $this->assertSame($target->id, $second->fresh()->folder_id);
    }

    public function test_moving_writes_one_audit_row_per_document(): void
    {
        $origin = $this->folder();
        $target = $this->folder();
        $document = $this->document(['folder_id' => $origin->id]);

        $this->actingAs($this->tenant['owner'])
            ->postJson('/api/documents/move-many', [
                'document_ids' => [$document->id],
                'folder_id' => $target->id,
            ])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'provider_id' => $this->tenant['provider']->id,
            'user_id' => $this->tenant['owner']->id,
            'action' => 'document.moved',
            'auditable_type' => Document::class,
            'auditable_id' => $document->id,
        ]);
    }

    public function test_moving_another_tenants_document_is_denied(): void
    {
        $target = $this->folder();
        $foreign = Document::factory()->create([
            'provider_id' => $this->otherTenant['provider']->id,
            'workspace_id' => $this->otherTenant['workspace']->id,
            'client_id' => $this->otherTenant['client']->id,
            'uploaded_by' => $this->otherTenant['owner']->id,
        ]);

        $this->actingAs($this->tenant['owner'])
            ->postJson('/api/documents/move-many', [
                'document_ids' => [$foreign->id],
                'folder_id' => $target->id,
            ])
            ->assertStatus(403);

        $this->assertNull($foreign->fresh()->folder_id);
    }

    public function test_moving_into_another_tenants_folder_is_refused(): void
    {
        $foreignFolder = Folder::factory()->create([
            'provider_id' => $this->otherTenant['provider']->id,
        ]);
        $document = $this->document(['folder_id' => null]);

        $this->actingAs($this->tenant['owner'])
            ->postJson('/api/documents/move-many', [
                'document_ids' => [$document->id],
                'folder_id' => $foreignFolder->id,
            ])
            ->assertStatus(422);

        $this->assertNull($document->fresh()->folder_id);
    }

    public function test_moving_rejects_an_empty_selection(): void
    {
        $this->actingAs($this->tenant['owner'])
            ->postJson('/api/documents/move-many', [
                'document_ids' => [],
                'folder_id' => $this->folder()->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('document_ids');
    }

    /* ── archive-many ──────────────────────────────────────────────────── */

    public function test_archiving_documents_requires_being_signed_in(): void
    {
        $this->postJson('/api/documents/archive-many', [
            'document_ids' => [$this->document()->id],
        ])->assertStatus(401);
    }

    public function test_it_archives_every_selected_document(): void
    {
        $first = $this->document();
        $second = $this->document();

        $this->actingAs($this->tenant['owner'])
            ->postJson('/api/documents/archive-many', [
                'document_ids' => [$first->id, $second->id],
            ])
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->assertNotNull($first->fresh()->archived_at);
        $this->assertNotNull($second->fresh()->archived_at);
    }

    public function test_bulk_archive_writes_the_same_audit_row_the_single_row_action_does(): void
    {
        $document = $this->document();

        $this->actingAs($this->tenant['owner'])
            ->postJson('/api/documents/archive-many', ['document_ids' => [$document->id]])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'document.archived',
            'auditable_type' => Document::class,
            'auditable_id' => $document->id,
        ]);
    }

    public function test_archiving_another_tenants_document_is_denied(): void
    {
        $foreign = Document::factory()->create([
            'provider_id' => $this->otherTenant['provider']->id,
            'workspace_id' => $this->otherTenant['workspace']->id,
            'client_id' => $this->otherTenant['client']->id,
            'uploaded_by' => $this->otherTenant['owner']->id,
        ]);

        $this->actingAs($this->tenant['owner'])
            ->postJson('/api/documents/archive-many', ['document_ids' => [$foreign->id]])
            ->assertStatus(403);

        $this->assertNull($foreign->fresh()->archived_at);
    }

    public function test_a_mixed_selection_archives_nothing_when_one_id_is_foreign(): void
    {
        $mine = $this->document();
        $foreign = Document::factory()->create([
            'provider_id' => $this->otherTenant['provider']->id,
            'workspace_id' => $this->otherTenant['workspace']->id,
            'client_id' => $this->otherTenant['client']->id,
            'uploaded_by' => $this->otherTenant['owner']->id,
        ]);

        $this->actingAs($this->tenant['owner'])
            ->postJson('/api/documents/archive-many', [
                'document_ids' => [$mine->id, $foreign->id],
            ])
            ->assertStatus(403);

        // authorizedDocuments() authorizes the whole selection before any of
        // it is acted on, so a poisoned selection is rejected outright rather
        // than half-applied.
        $this->assertNull($mine->fresh()->archived_at);
        $this->assertNull($foreign->fresh()->archived_at);
    }

    public function test_archiving_rejects_an_empty_selection(): void
    {
        $this->actingAs($this->tenant['owner'])
            ->postJson('/api/documents/archive-many', ['document_ids' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('document_ids');
    }

    /* ── zip ───────────────────────────────────────────────────────────── */

    public function test_zipping_documents_requires_being_signed_in(): void
    {
        $this->postJson('/api/documents/zip', [
            'document_ids' => [$this->document()->id],
        ])->assertStatus(401);
    }

    public function test_it_returns_a_zip_containing_every_selected_document(): void
    {
        $first = $this->document(['name' => 'Retainer.pdf']);
        $second = $this->document(['name' => 'NDA.pdf']);

        $response = $this->actingAs($this->tenant['owner'])
            ->post('/api/documents/zip', ['document_ids' => [$first->id, $second->id]]);

        $response->assertOk();
        $this->assertSame('application/zip', $response->headers->get('content-type'));

        $names = $this->namesInZip($this->zipBytes($response));

        $this->assertContains('Retainer.pdf', $names);
        $this->assertContains('NDA.pdf', $names);
    }

    public function test_duplicate_file_names_are_disambiguated_rather_than_overwritten(): void
    {
        $first = $this->document(['name' => 'Agreement.pdf']);
        $second = $this->document(['name' => 'Agreement.pdf']);

        $response = $this->actingAs($this->tenant['owner'])
            ->post('/api/documents/zip', ['document_ids' => [$first->id, $second->id]]);

        $response->assertOk();

        $names = $this->namesInZip($this->zipBytes($response));

        $this->assertCount(2, $names, 'A second document with the same name must not overwrite the first.');
        $this->assertContains('Agreement.pdf', $names);
        $this->assertContains('Agreement (2).pdf', $names);
    }

    public function test_zipping_writes_a_download_audit_row_per_document(): void
    {
        $document = $this->document(['name' => 'Retainer.pdf']);

        $this->actingAs($this->tenant['owner'])
            ->post('/api/documents/zip', ['document_ids' => [$document->id]])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'document.downloaded',
            'auditable_type' => Document::class,
            'auditable_id' => $document->id,
        ]);
    }

    public function test_zipping_another_tenants_document_is_denied(): void
    {
        $foreign = Document::factory()->create([
            'provider_id' => $this->otherTenant['provider']->id,
            'workspace_id' => $this->otherTenant['workspace']->id,
            'client_id' => $this->otherTenant['client']->id,
            'uploaded_by' => $this->otherTenant['owner']->id,
        ]);

        $this->actingAs($this->tenant['owner'])
            ->postJson('/api/documents/zip', ['document_ids' => [$foreign->id]])
            ->assertStatus(403);
    }

    public function test_zipping_rejects_an_empty_selection(): void
    {
        $this->actingAs($this->tenant['owner'])
            ->postJson('/api/documents/zip', ['document_ids' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('document_ids');
    }

    /**
     * The zip's raw bytes. The controller answers with `response()->download()`
     * — a BinaryFileResponse, whose body lives on disk rather than in
     * `getContent()`/`streamedContent()` — so read the file it points at.
     */
    private function zipBytes(TestResponse $response): string
    {
        $base = $response->baseResponse;

        if ($base instanceof BinaryFileResponse) {
            return (string) file_get_contents($base->getFile()->getPathname());
        }

        return $response->streamedContent();
    }

    /**
     * @return list<string> the entry names inside a zip given as raw bytes
     */
    private function namesInZip(string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'pacttrack-test-zip-');
        file_put_contents($path, $bytes);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true, 'The response was not a readable zip archive.');

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }

        $zip->close();
        @unlink($path);

        return $names;
    }
}
