<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Matter\Tests;

use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\Matter\Infrastructure\Services\MatterActivityFeedBuilder;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Enums\EnvelopeStatus;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * See .claude/rules/signature.md, "Audit Trail" — this covers
 * buildForEnvelope() (the envelope detail view's own feed) and the
 * envelope_voided entry both it and build() (the portal's Recent Updates
 * feed) now share via envelopeEntries().
 */
class MatterActivityFeedBuilderTest extends BaseTest
{
    private MatterActivityFeedBuilder $builder;

    private TestScenarioCollection $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->builder = app(MatterActivityFeedBuilder::class);
        $this->tenant = ProviderTenantScenario::make('activity-feed');
    }

    public function test_build_for_envelope_includes_the_documents_upload_and_the_envelopes_send(): void
    {
        $document = Document::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'uploaded_by' => $this->tenant['owner']->id,
            'name' => 'Engagement Letter.pdf',
        ]);
        $envelope = Envelope::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'document_id' => $document->id,
            'status' => EnvelopeStatus::Sent,
            'sent_at' => now(),
        ]);
        $envelope->load(['document.uploader', 'signers', 'provider']);

        $entries = $this->builder->buildForEnvelope($envelope);
        $titles = array_column($entries, 'title');

        $this->assertContains('Engagement Letter.pdf uploaded', $titles);
        $this->assertContains('Engagement Letter.pdf sent for signature', $titles);
    }

    public function test_a_voided_envelope_contributes_a_voided_entry(): void
    {
        $document = Document::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'uploaded_by' => $this->tenant['owner']->id,
            'name' => 'NDA.pdf',
        ]);
        $envelope = Envelope::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'document_id' => $document->id,
            'status' => EnvelopeStatus::Voided,
        ]);
        $envelope->load(['document.uploader', 'signers', 'provider']);

        $entries = $this->builder->buildForEnvelope($envelope);
        $voided = collect($entries)->firstWhere('type', 'envelope_voided');

        $this->assertNotNull($voided);
        $this->assertSame('NDA.pdf voided', $voided['title']);
    }

    public function test_a_non_voided_envelope_contributes_no_voided_entry(): void
    {
        $document = Document::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'uploaded_by' => $this->tenant['owner']->id,
        ]);
        $envelope = Envelope::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'document_id' => $document->id,
            'status' => EnvelopeStatus::Sent,
            'sent_at' => now(),
        ]);
        $envelope->load(['document.uploader', 'signers', 'provider']);

        $entries = $this->builder->buildForEnvelope($envelope);

        $this->assertNull(collect($entries)->firstWhere('type', 'envelope_voided'));
    }

    public function test_build_for_matter_also_surfaces_a_voided_envelope(): void
    {
        $document = Document::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'uploaded_by' => $this->tenant['owner']->id,
            'matter_id' => $this->tenant['matter']->id,
            'name' => 'Retainer.pdf',
        ]);
        Envelope::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'document_id' => $document->id,
            'status' => EnvelopeStatus::Voided,
        ]);

        $matter = $this->tenant['matter']->load([
            'provider',
            'milestones',
            'documents.uploader',
            'documents.envelopes.signers',
        ]);

        $entries = $this->builder->build($matter);
        $titles = array_column($entries, 'title');

        $this->assertContains('Retainer.pdf voided', $titles);
    }
}
