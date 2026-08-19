<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Tests;

use Illuminate\Support\Facades\Storage;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\TestCase\Extras\LoadsModuleApiRoutes;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * The manual "Prepare for Signature" row action's HTTP surface — see
 * .claude/rules/signature.md. DocumentUploadTriggersSignatureTest covers the
 * upload-time auto-trigger version of the same PDF-only guard.
 */
class EnvelopeControllerTest extends BaseTest
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

        $this->tenant = ProviderTenantScenario::make('envelope-controller');
    }

    public function test_it_prepares_a_pdf_document_and_returns_a_sender_view_url(): void
    {
        // ProviderTenantScenario's fixture document defaults to
        // application/pdf (DocumentFactory) but doesn't write bytes to the
        // fake disk — PrepareEnvelopeForSignature checks the file exists.
        Storage::disk(self::DISK)->put($this->tenant['document']->s3_path, 'pdf-bytes');

        $response = $this->actingAs($this->tenant['owner'])
            ->postJson("/api/signature/documents/{$this->tenant['document']->id}/prepare");

        $response->assertSuccessful();
        $this->assertNotNull($response->json('sender_view_url'));
    }

    public function test_it_reports_live_provider_status_for_a_sent_envelope(): void
    {
        Storage::disk(self::DISK)->put($this->tenant['document']->s3_path, 'pdf-bytes');

        $prepared = $this->actingAs($this->tenant['owner'])
            ->postJson("/api/signature/documents/{$this->tenant['document']->id}/prepare");

        $response = $this->actingAs($this->tenant['owner'])
            ->getJson("/api/signature/envelopes/{$prepared->json('envelope_id')}/status");

        $response->assertOk()->assertJsonPath('status', 'sent');
    }

    public function test_it_ignores_a_stale_draft_envelope_from_a_different_provider(): void
    {
        // Simulates a pre-DocuSign-migration Documenso draft row left behind
        // on this document — its provider_envelope_id means nothing to
        // DocuSign, so it must never be reused as if it were a resumable
        // DocuSign draft. Regression test for the bug where the idempotency
        // lookup didn't filter by provider.
        Storage::disk(self::DISK)->put($this->tenant['document']->s3_path, 'pdf-bytes');

        Envelope::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'document_id' => $this->tenant['document']->id,
            'provider' => 'documenso',
            'provider_envelope_id' => 'envelope_stale123',
            'status' => 'draft',
        ]);

        $response = $this->actingAs($this->tenant['owner'])
            ->postJson("/api/signature/documents/{$this->tenant['document']->id}/prepare");

        $response->assertSuccessful();
        $this->assertDatabaseHas('envelopes', [
            'document_id' => $this->tenant['document']->id,
            'provider' => 'docusign',
        ]);
    }

    public function test_the_sender_view_return_url_points_at_the_shared_docusign_return_route(): void
    {
        Storage::disk(self::DISK)->put($this->tenant['document']->s3_path, 'pdf-bytes');

        $response = $this->actingAs($this->tenant['owner'])
            ->postJson("/api/signature/documents/{$this->tenant['document']->id}/prepare");

        $response->assertSuccessful();
        $senderViewUrl = urldecode((string) $response->json('sender_view_url'));

        $this->assertStringContainsString('/docusign-return?flow=sender&envelope=', $senderViewUrl);
    }

    public function test_it_refuses_a_non_pdf_document_with_a_422(): void
    {
        $document = Document::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ]);

        $response = $this->actingAs($this->tenant['owner'])
            ->postJson("/api/signature/documents/{$document->id}/prepare");

        $response->assertStatus(422);
        $this->assertDatabaseMissing('envelopes', ['document_id' => $document->id]);
    }
}
