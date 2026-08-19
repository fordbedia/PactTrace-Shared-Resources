<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Document\Tests;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Ports\ESignatureProvider;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\ValueObjects\EnvelopeRecipient;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\ValueObjects\SigningToken;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\ValueObjects\WebhookEvent;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\TestCase\Extras\LoadsModuleApiRoutes;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;
use RuntimeException;

/**
 * The auto-trigger wired into DocumentController::store() — a client-attached
 * upload immediately gets a draft signature envelope rather than sitting in
 * a dead-end state. See .claude/rules/signature.md and this class's sibling
 * DocumentControllerTest for the rest of the upload endpoint's coverage.
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

    public function test_uploading_with_a_client_creates_a_draft_envelope_and_returns_its_sender_view_url(): void
    {
        $response = $this->actingAs($this->tenant['owner'])->postJson('/api/documents', [
            'file' => UploadedFile::fake()->create('retainer.pdf', 4),
            'client_id' => $this->tenant['client']->id,
        ]);

        $response->assertSuccessful();
        $documentId = $response->json('data.id');

        $this->assertNotNull($response->json('envelope.sender_view_url'));
        $this->assertDatabaseHas('envelopes', [
            'document_id' => $documentId,
            'client_id' => $this->tenant['client']->id,
            'status' => 'draft',
        ]);
    }

    public function test_uploading_a_non_pdf_with_a_client_creates_no_envelope(): void
    {
        $response = $this->actingAs($this->tenant['owner'])->postJson('/api/documents', [
            'file' => UploadedFile::fake()->create('retainer.docx', 4),
            'client_id' => $this->tenant['client']->id,
        ]);

        $response->assertSuccessful();

        $this->assertNull($response->json('envelope'));
        $this->assertDatabaseMissing('envelopes', ['document_id' => $response->json('data.id')]);
    }

    public function test_uploading_without_a_client_creates_no_envelope(): void
    {
        $response = $this->actingAs($this->tenant['owner'])->postJson('/api/documents', [
            'file' => UploadedFile::fake()->create('internal-note.pdf', 4),
        ]);

        $response->assertSuccessful();

        $this->assertNull($response->json('envelope'));
        $this->assertDatabaseMissing('envelopes', ['document_id' => $response->json('data.id')]);
    }

    public function test_a_provider_failure_does_not_fail_the_upload(): void
    {
        $this->app->bind(ESignatureProvider::class, function () {
            return new class implements ESignatureProvider {
                public function createDraftEnvelope(string $title, string $fileName, string $fileContents, EnvelopeRecipient $recipient, ?string $externalId = null): string
                {
                    throw new RuntimeException('DocuSign is unreachable.');
                }

                public function senderViewUrl(string $providerEnvelopeId, string $returnUrl): string
                {
                    return 'unused';
                }

                public function recipientViewUrl(string $providerEnvelopeId, EnvelopeRecipient $recipient, string $returnUrl): SigningToken
                {
                    throw new RuntimeException('unused');
                }

                public function applyBrand(string $providerEnvelopeId, ?string $brandId): void
                {
                    // unused
                }

                public function fetchEnvelopeStatus(string $providerEnvelopeId): string
                {
                    return 'sent';
                }

                public function verifyWebhookSignature(string $rawPayload, ?string $signatureHeader): bool
                {
                    return true;
                }

                public function normalizeWebhookEvent(array $payload): WebhookEvent
                {
                    return WebhookEvent::fromDocusignPayload($payload);
                }
            };
        });

        $response = $this->actingAs($this->tenant['owner'])->postJson('/api/documents', [
            'file' => UploadedFile::fake()->create('retainer.pdf', 4),
            'client_id' => $this->tenant['client']->id,
        ]);

        $response->assertSuccessful()->assertJsonPath('envelope', null);

        $document = \PactTrackSDK\SharedResources\Modules\Document\Models\Document::query()
            ->findOrFail($response->json('data.id'));
        Storage::disk(self::DISK)->assertExists($document->s3_path);
        $this->assertDatabaseMissing('envelopes', ['document_id' => $document->id]);
    }
}
