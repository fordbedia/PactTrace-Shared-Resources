<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Document\Tests;

use Illuminate\Support\Facades\Storage;
use PactTrackSDK\SharedResources\Modules\Document\Domain\Enums\DocumentStatus;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Enums\EnvelopeStatus;
use PactTrackSDK\SharedResources\Modules\Signature\Jobs\StoreSignedDocumentCopy;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\TestCase\Extras\LoadsModuleApiRoutes;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * Proves the Download endpoint needs no signature-status-aware branching to
 * serve the right file — see .claude/rules/document.md, "Signed document
 * storage". `documents.s3_path` is always "whatever is current"; once
 * StoreSignedDocumentCopy has run, that's the signed file, and
 * DocumentController::download() (unchanged by this feature) resolves it the
 * same way it always has.
 */
class DocumentDownloadServesSignedCopyTest extends BaseTest
{
    use LoadsModuleApiRoutes;

    private const DISK = 'signed-download-test';

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

        $this->tenant = ProviderTenantScenario::make('doc-download-signed');
    }

    public function test_downloading_a_completed_document_returns_the_signed_bytes_not_the_original(): void
    {
        $document = $this->tenant['document'];
        $document->forceFill(['status' => DocumentStatus::Completed, 's3_path' => 'documents/original.pdf'])->save();
        Storage::disk(self::DISK)->put($document->s3_path, 'the-original-bytes');

        $envelope = Envelope::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'document_id' => $document->id,
            'status' => EnvelopeStatus::Completed,
            'provider_envelope_id' => 'docusign-env-' . $document->id,
        ]);

        StoreSignedDocumentCopy::dispatchSync($envelope->id);

        $response = $this->actingAs($this->tenant['owner'])->get("/api/documents/{$document->id}/download");

        $response->assertRedirect();
        $signedPath = $document->fresh()->s3_path;
        $this->assertNotSame('documents/original.pdf', $signedPath);
        $this->assertStringContainsString($signedPath, (string) $response->headers->get('Location'));
    }

    public function test_downloading_a_document_with_no_signed_copy_yet_still_returns_the_original(): void
    {
        $document = $this->tenant['document'];
        $document->forceFill(['status' => DocumentStatus::Sent, 's3_path' => 'documents/original.pdf'])->save();
        Storage::disk(self::DISK)->put($document->s3_path, 'the-original-bytes');

        $response = $this->actingAs($this->tenant['owner'])->get("/api/documents/{$document->id}/download");

        $response->assertRedirect();
        $this->assertStringContainsString('documents/original.pdf', (string) $response->headers->get('Location'));
    }
}
