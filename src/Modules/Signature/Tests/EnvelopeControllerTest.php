<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Tests;

use Illuminate\Support\Facades\Storage;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Signer;
use PactTrackSDK\SharedResources\TestCase\Extras\LoadsModuleApiRoutes;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * The "Prepare for Signature" HTTP surface — see .claude/rules/signature.md.
 * DocumentUploadTriggersSignatureTest (Document module) covers that
 * uploading never triggers this itself.
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
        $document = $this->freshPdfDocument();

        $response = $this->actingAs($this->tenant['owner'])
            ->postJson("/api/signature/documents/{$document->id}/prepare");

        $response->assertSuccessful();
        $this->assertNotNull($response->json('sender_view_url'));
    }

    public function test_it_reports_live_provider_status_for_a_sent_envelope(): void
    {
        $document = $this->freshPdfDocument();

        $prepared = $this->actingAs($this->tenant['owner'])
            ->postJson("/api/signature/documents/{$document->id}/prepare");

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
        $document = $this->freshPdfDocument();

        Envelope::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'document_id' => $document->id,
            'provider' => 'documenso',
            'provider_envelope_id' => 'envelope_stale123',
            'status' => 'draft',
        ]);

        $response = $this->actingAs($this->tenant['owner'])
            ->postJson("/api/signature/documents/{$document->id}/prepare");

        $response->assertSuccessful();
        $this->assertDatabaseHas('envelopes', [
            'document_id' => $document->id,
            'provider' => 'docusign',
        ]);
    }

    public function test_the_sender_view_return_url_points_at_the_shared_docusign_return_route(): void
    {
        $document = $this->freshPdfDocument();

        $response = $this->actingAs($this->tenant['owner'])
            ->postJson("/api/signature/documents/{$document->id}/prepare");

        $response->assertSuccessful();
        $senderViewUrl = urldecode((string) $response->json('sender_view_url'));

        $this->assertStringContainsString('/docusign-return?flow=sender&envelope=', $senderViewUrl);
    }

    public function test_it_creates_a_pending_signer_row_for_the_document_client(): void
    {
        $document = $this->freshPdfDocument();

        $response = $this->actingAs($this->tenant['owner'])
            ->postJson("/api/signature/documents/{$document->id}/prepare");

        $this->assertDatabaseHas('signers', [
            'envelope_id' => $response->json('envelope_id'),
            'email' => $this->tenant['client']->email,
            'provider_signer_id' => '1',
            'status' => 'pending',
        ]);
    }

    public function test_it_accepts_additional_co_signers_and_creates_a_pending_row_for_each(): void
    {
        $document = $this->freshPdfDocument();

        $response = $this->actingAs($this->tenant['owner'])
            ->postJson("/api/signature/documents/{$document->id}/prepare", [
                'signers' => [
                    ['name' => 'Co Signer One', 'email' => 'co1@example.com'],
                    ['name' => 'Co Signer Two', 'email' => 'co2@example.com'],
                ],
            ]);

        $response->assertSuccessful();
        $this->assertSame(3, Signer::query()->where('envelope_id', $response->json('envelope_id'))->count());
        $this->assertDatabaseHas('signers', [
            'envelope_id' => $response->json('envelope_id'),
            'email' => 'co1@example.com',
            'provider_signer_id' => '2',
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('signers', [
            'envelope_id' => $response->json('envelope_id'),
            'email' => 'co2@example.com',
            'provider_signer_id' => '3',
            'status' => 'pending',
        ]);
    }

    public function test_it_rejects_duplicate_co_signer_emails_with_a_422(): void
    {
        $document = $this->freshPdfDocument();

        $response = $this->actingAs($this->tenant['owner'])
            ->postJson("/api/signature/documents/{$document->id}/prepare", [
                'signers' => [
                    ['name' => 'Co Signer', 'email' => 'dup@example.com'],
                    ['name' => 'Co Signer Again', 'email' => 'dup@example.com'],
                ],
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('envelopes', ['document_id' => $document->id]);
    }

    public function test_draft_signers_returns_empty_when_no_draft_envelope_exists(): void
    {
        $document = $this->freshPdfDocument();

        $response = $this->actingAs($this->tenant['owner'])
            ->getJson("/api/signature/documents/{$document->id}/prepare");

        $response->assertOk()
            ->assertJsonPath('envelope_id', null)
            ->assertJsonPath('signers', []);
    }

    public function test_draft_signers_returns_every_signer_on_an_existing_draft(): void
    {
        $document = $this->freshPdfDocument();

        $prepared = $this->actingAs($this->tenant['owner'])
            ->postJson("/api/signature/documents/{$document->id}/prepare", [
                'signers' => [['name' => 'Co Signer', 'email' => 'co@example.com']],
            ]);

        $response = $this->actingAs($this->tenant['owner'])
            ->getJson("/api/signature/documents/{$document->id}/prepare");

        $response->assertOk()->assertJsonPath('envelope_id', $prepared->json('envelope_id'));
        $this->assertCount(2, $response->json('signers'));
        $this->assertContains('co@example.com', array_column($response->json('signers'), 'email'));
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

    /**
     * A document of the tenant's own, distinct from `tenant['document']` —
     * ProviderTenantScenario's shared fixture already gives that one a
     * randomly-statused envelope (EnvelopeFactory), which would collide with
     * this class's own draft-reuse/idempotency assertions. PDF by default
     * (DocumentFactory) with real bytes on the fake disk, since
     * PrepareEnvelopeForSignature checks the file exists.
     */
    private function freshPdfDocument(): Document
    {
        $document = Document::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
        ]);
        Storage::disk(self::DISK)->put($document->s3_path, 'pdf-bytes');

        return $document;
    }
}
