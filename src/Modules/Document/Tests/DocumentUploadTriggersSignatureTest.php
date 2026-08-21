<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Document\Tests;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PactTrackSDK\SharedResources\TestCase\Extras\LoadsModuleApiRoutes;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * DocumentController::store() never creates a signature envelope itself —
 * PrepareEnvelopeForSignature needs the full recipient list (client plus
 * any ad-hoc co-signers) up front, and there's nowhere on the upload form
 * to name co-signers. The frontend instead opens PrepareSignatureModal
 * right after a client-attached upload to collect that list, then calls
 * EnvelopeController::prepare() itself — the same call the manual "Prepare
 * for Signature" row action makes. See .claude/rules/signature.md.
 */
class DocumentUploadTriggersSignatureTest extends BaseTest
{
    use LoadsModuleApiRoutes;

    private const DISK = 'documents-test';

    private TestScenarioCollection $tenant;

    protected function moduleApiRoutes(): array
    {
        return [__DIR__ . '/../routes/api.php'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(self::DISK);
        config(['filesystems.document_disk' => self::DISK]);

        $this->tenant = ProviderTenantScenario::make('upload-signature');
    }

    public function test_uploading_a_pdf_with_a_client_creates_no_envelope(): void
    {
        $response = $this->actingAs($this->tenant['owner'])->postJson('/api/documents', [
            'file' => UploadedFile::fake()->create('retainer.pdf', 4),
            'client_id' => $this->tenant['client']->id,
        ]);

        $response->assertSuccessful()->assertJsonMissingPath('envelope');

        $this->assertDatabaseMissing('envelopes', ['document_id' => $response->json('data.id')]);
    }

    public function test_uploading_without_a_client_creates_no_envelope(): void
    {
        $response = $this->actingAs($this->tenant['owner'])->postJson('/api/documents', [
            'file' => UploadedFile::fake()->create('internal-note.pdf', 4),
        ]);

        $response->assertSuccessful();

        $this->assertDatabaseMissing('envelopes', ['document_id' => $response->json('data.id')]);
    }
}
