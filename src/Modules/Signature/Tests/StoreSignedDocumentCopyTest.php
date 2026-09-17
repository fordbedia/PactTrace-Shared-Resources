<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Tests;

use Illuminate\Support\Facades\Storage;
use PactTrackSDK\SharedResources\Modules\Document\Domain\Enums\DocumentVersionType;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\Document\Models\DocumentVersion;
use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Enums\EnvelopeStatus;
use PactTrackSDK\SharedResources\Modules\Signature\Jobs\StoreSignedDocumentCopy;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * The job that turns an envelope reaching `completed` into the signed
 * document actually becoming the Document's stored content — see
 * .claude/rules/signature.md, "Fetching the signed document after
 * completion" and .claude/rules/document.md, "Signed document storage".
 * FakeSignatureProvider (bound app-wide, see BaseTest) stands in for the
 * real DocuSign call.
 */
class StoreSignedDocumentCopyTest extends BaseTest
{
    private const DISK = 'signature-copy-test';

    private TestScenarioCollection $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(self::DISK);
        config(['filesystems.document_disk' => self::DISK]);

        $this->tenant = ProviderTenantScenario::make('store-signed-copy');
    }

    private function completedEnvelope(): Envelope
    {
        $document = $this->tenant['document'];
        $document->forceFill(['s3_path' => 'documents/original.pdf', 'size' => 1000, 'version' => 1])->save();

        $this->tenant['provider']->forceFill(['storage_used_bytes' => 1000])->save();

        return Envelope::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'document_id' => $document->id,
            'status' => EnvelopeStatus::Completed,
            'provider_envelope_id' => 'docusign-env-' . $document->id,
        ]);
    }

    public function test_it_archives_the_original_and_makes_the_signed_document_current(): void
    {
        $envelope = $this->completedEnvelope();
        $originalPath = $envelope->document->s3_path;

        StoreSignedDocumentCopy::dispatchSync($envelope->id);

        $document = $envelope->document->fresh();
        $this->assertNotSame($originalPath, $document->s3_path);
        $this->assertSame('application/pdf', $document->mime_type);
        $this->assertSame(2, $document->version);
        $this->assertSame(
            strlen("%FAKE-SIGNED-PDF%\ncontent-for-envelope:{$envelope->provider_envelope_id}"),
            $document->size,
        );
        Storage::disk(self::DISK)->assertExists($document->s3_path);
    }

    public function test_it_archives_the_pre_signature_content_as_a_document_version(): void
    {
        $envelope = $this->completedEnvelope();
        $originalPath = $envelope->document->s3_path;
        $originalSize = $envelope->document->size;

        StoreSignedDocumentCopy::dispatchSync($envelope->id);

        $version = DocumentVersion::query()->where('document_id', $envelope->document_id)->firstOrFail();
        $this->assertSame($originalPath, $version->s3_path);
        $this->assertSame($originalSize, $version->size);
        $this->assertSame(1, $version->version);
        $this->assertSame(DocumentVersionType::Original, $version->type);
    }

    public function test_it_marks_the_envelope_as_having_its_signed_copy_stored(): void
    {
        $envelope = $this->completedEnvelope();

        StoreSignedDocumentCopy::dispatchSync($envelope->id);

        $this->assertNotNull($envelope->fresh()->signed_document_stored_at);
    }

    public function test_it_writes_an_audit_log_entry(): void
    {
        $envelope = $this->completedEnvelope();

        StoreSignedDocumentCopy::dispatchSync($envelope->id);

        $this->assertDatabaseHas('audit_logs', [
            'provider_id' => $this->tenant['provider']->id,
            'action' => 'document.signed_copy_stored',
            'auditable_type' => Document::class,
            'auditable_id' => $envelope->document_id,
        ]);
    }

    public function test_it_updates_the_cached_storage_total_by_the_net_size_change(): void
    {
        $envelope = $this->completedEnvelope();

        StoreSignedDocumentCopy::dispatchSync($envelope->id);

        $document = $envelope->document->fresh();
        $this->assertSame((int) $document->size, (int) $this->tenant['provider']->fresh()->storage_used_bytes);
    }

    public function test_a_second_run_for_the_same_envelope_is_a_no_op(): void
    {
        $envelope = $this->completedEnvelope();

        StoreSignedDocumentCopy::dispatchSync($envelope->id);
        $afterFirstRun = $envelope->document->fresh()->version;

        StoreSignedDocumentCopy::dispatchSync($envelope->id);

        $this->assertSame($afterFirstRun, $envelope->document->fresh()->version);
        $this->assertSame(
            1,
            DocumentVersion::query()->where('document_id', $envelope->document_id)->count(),
        );
        $this->assertSame(
            1,
            AuditLog::query()->where('action', 'document.signed_copy_stored')->count(),
        );
    }

    public function test_a_re_signed_document_archives_the_prior_signed_copy_not_original(): void
    {
        $envelope = $this->completedEnvelope();
        StoreSignedDocumentCopy::dispatchSync($envelope->id);

        // Simulate the document having been re-prepared and re-signed under
        // a second envelope after the first was voided — see
        // .claude/rules/signature.md, "Every recipient is a DocuSign Signer".
        $secondEnvelope = Envelope::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'document_id' => $envelope->document_id,
            'status' => EnvelopeStatus::Completed,
            'provider_envelope_id' => 'docusign-env-second-' . $envelope->document_id,
        ]);

        StoreSignedDocumentCopy::dispatchSync($secondEnvelope->id);

        $versions = DocumentVersion::query()->where('document_id', $envelope->document_id)->orderBy('id')->get();
        $this->assertCount(2, $versions);
        $this->assertSame(DocumentVersionType::Original, $versions[0]->type);
        $this->assertSame(DocumentVersionType::Signed, $versions[1]->type);
    }

    public function test_it_does_nothing_for_a_nonexistent_envelope(): void
    {
        StoreSignedDocumentCopy::dispatchSync(999999);

        $this->assertSame(0, DocumentVersion::query()->count());
    }
}
