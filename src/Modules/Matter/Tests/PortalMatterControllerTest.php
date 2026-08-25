<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Matter\Tests;

use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\Matter\Models\Milestone;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Signer;
use PactTrackSDK\SharedResources\TestCase\Extras\LoadsModuleApiRoutes;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * The client portal's matter list/detail HTTP surface — see
 * .claude/rules/matter.md and PortalMatterController's own docblock.
 */
class PortalMatterControllerTest extends BaseTest
{
    use LoadsModuleApiRoutes;

    private TestScenarioCollection $tenant;

    protected function moduleApiRoutes(): array
    {
        return [__DIR__ . '/../routes/api.php'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = ProviderTenantScenario::make('portal-matter');
    }

    public function test_a_client_sees_real_data_for_their_own_matter(): void
    {
        $matter = $this->tenant['matter'];

        Milestone::query()->where('matter_id', $matter->id)->delete();
        Milestone::factory()->create([
            'matter_id' => $matter->id,
            'name' => 'Engagement',
            'status' => 'completed',
            'position' => 0,
            'completed_at' => now()->subDays(5),
        ]);
        Milestone::factory()->create([
            'matter_id' => $matter->id,
            'name' => 'Discovery',
            'status' => 'in_progress',
            'position' => 1,
            'completed_at' => null,
        ]);

        // A completed, signed document on this matter — distinct from the
        // scenario's own default document, which is left at its factory
        // default `draft` status on purpose (see the assertion below).
        $document = Document::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'matter_id' => $matter->id,
            'uploaded_by' => $this->tenant['owner']->id,
            'name' => 'Trust Agreement.pdf',
            'status' => 'completed',
        ]);

        $envelope = Envelope::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'document_id' => $document->id,
            'status' => 'completed',
            'sent_at' => now()->subDays(2),
            'completed_at' => now()->subDay(),
        ]);

        Signer::factory()->create([
            'envelope_id' => $envelope->id,
            'name' => $this->tenant['client']->name,
            'email' => $this->tenant['client']->email,
            'status' => 'signed',
            'signed_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($this->tenant['clientUser'])
            ->getJson("/api/v1/portal/matters/{$matter->public_id}");

        $response->assertOk();
        // A single Resource returned directly from a controller wraps in a
        // top-level "data" key by default (same as MatterResource/
        // DocumentResource elsewhere in this codebase) — see
        // MattersContext.tsx's `'data' in data ? data.data : data` handling
        // of this exact shape.
        $response->assertJsonPath('data.id', $matter->public_id);
        $response->assertJsonPath('data.name', $matter->name);
        $response->assertJsonPath('data.status', $matter->status);

        $milestoneStates = array_column($response->json('data.milestones'), 'state', 'name');
        $this->assertSame('done', $milestoneStates['Engagement']);
        $this->assertSame('current', $milestoneStates['Discovery']);

        $documentNames = array_column($response->json('data.documents'), 'name');
        $this->assertContains('Trust Agreement.pdf', $documentNames);
        // The scenario's own default document is left at `draft` — a
        // client must never see a document that hasn't been sent to them
        // yet. See GetMatterPortalDetailHandler's own note: this is the
        // one place this feature flags as a genuine product ambiguity
        // rather than deciding it silently.
        $this->assertNotContains($this->tenant['document']->name, $documentNames);

        $activityTitles = array_column($response->json('data.activity'), 'title');
        $this->assertContains('Trust Agreement.pdf sent for signature', $activityTitles);
        $this->assertContains('Trust Agreement.pdf signed & completed', $activityTitles);
    }

    public function test_a_client_cannot_view_a_matter_belonging_to_a_different_client(): void
    {
        $response = $this->actingAs($this->tenant['clientUser'])
            ->getJson("/api/v1/portal/matters/{$this->tenant['otherMatter']->public_id}");

        $response->assertStatus(403);
    }

    public function test_activity_feed_distinguishes_the_primary_client_from_a_guest_signer(): void
    {
        $matter = $this->tenant['matter'];

        $document = Document::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'matter_id' => $matter->id,
            'uploaded_by' => $this->tenant['owner']->id,
            'status' => 'partially_signed',
        ]);

        $envelope = Envelope::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'document_id' => $document->id,
            'status' => 'partially_signed',
            'sent_at' => now()->subDay(),
            'completed_at' => null,
        ]);

        Signer::factory()->create([
            'envelope_id' => $envelope->id,
            'name' => $this->tenant['client']->name,
            'email' => $this->tenant['client']->email,
            'provider_signer_id' => '1',
            'status' => 'signed',
            'signed_at' => now()->subHours(5),
        ]);

        Signer::factory()->create([
            'envelope_id' => $envelope->id,
            'name' => 'Co-Signer Guest',
            'email' => 'guest@example.com',
            'provider_signer_id' => '2',
            'status' => 'signed',
            'signed_at' => now()->subHours(2),
            'signing_token_hash' => hash('sha256', 'irrelevant-for-this-test'),
        ]);

        $response = $this->actingAs($this->tenant['clientUser'])
            ->getJson("/api/v1/portal/matters/{$matter->public_id}");

        $response->assertOk();

        $actors = array_column($response->json('data.activity'), 'actor');

        $this->assertContains('Signed by ' . $this->tenant['client']->name, $actors);
        $this->assertContains('Signed by Co-Signer Guest (guest signer)', $actors);
    }

    public function test_show_resolves_by_public_id_not_the_internal_id(): void
    {
        $matter = $this->tenant['matter'];

        $byPublicId = $this->actingAs($this->tenant['clientUser'])
            ->getJson("/api/v1/portal/matters/{$matter->public_id}");
        $byPublicId->assertOk();

        $byInternalId = $this->actingAs($this->tenant['clientUser'])
            ->getJson("/api/v1/portal/matters/{$matter->id}");
        $byInternalId->assertStatus(404);
    }

    public function test_index_lists_only_the_signed_in_clients_own_matters(): void
    {
        $response = $this->actingAs($this->tenant['clientUser'])->getJson('/api/v1/portal/matters');

        $response->assertOk();
        $ids = array_column($response->json('data'), 'id');

        $this->assertContains($this->tenant['matter']->public_id, $ids);
        $this->assertNotContains($this->tenant['otherMatter']->public_id, $ids);
    }

    /**
     * A Matter is reused for the whole engagement, so more than one Document
     * — each with its own Envelope — can legitimately be pending signature
     * at once (see .claude/rules/matter.md). Both must come back from this
     * matter-scoped endpoint so the portal can render a "Review & Sign"
     * action for each, instead of the old single-envelope card sourced from
     * the unscoped `GET /api/signature/pending`.
     */
    public function test_pending_envelopes_includes_every_non_terminal_envelope_on_the_matter(): void
    {
        $matter = $this->tenant['matter'];
        // The scenario's own baseline envelope defaults to a non-terminal
        // status too — remove it so this test's assertions reflect only the
        // two envelopes it explicitly sets up.
        $this->tenant['envelope']->delete();

        $documentOne = Document::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'matter_id' => $matter->id,
            'uploaded_by' => $this->tenant['owner']->id,
            'name' => 'Engagement Letter.pdf',
            'status' => 'sent',
        ]);
        $envelopeOne = Envelope::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'document_id' => $documentOne->id,
            'status' => 'sent',
            'sent_at' => now()->subDay(),
        ]);

        $documentTwo = Document::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'matter_id' => $matter->id,
            'uploaded_by' => $this->tenant['owner']->id,
            'name' => 'NDA.pdf',
            'status' => 'partially_signed',
        ]);
        $envelopeTwo = Envelope::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'document_id' => $documentTwo->id,
            'status' => 'partially_signed',
            'sent_at' => now()->subHours(6),
        ]);

        $response = $this->actingAs($this->tenant['clientUser'])
            ->getJson("/api/v1/portal/matters/{$matter->public_id}");

        $response->assertOk();

        $envelopeIds = array_column($response->json('data.pending_envelopes'), 'envelope_id');
        $this->assertContains($envelopeOne->public_id, $envelopeIds);
        $this->assertContains($envelopeTwo->public_id, $envelopeIds);
        $this->assertCount(2, $envelopeIds);

        $byId = array_column($response->json('data.pending_envelopes'), null, 'envelope_id');
        $this->assertSame('NDA.pdf', $byId[$envelopeTwo->public_id]['document_name']);
        $this->assertSame('partially_signed', $byId[$envelopeTwo->public_id]['status']);
    }

    /**
     * Once one of a matter's two envelopes reaches a terminal state
     * (completed here), it must drop out of `pending_envelopes` while the
     * other, still-active envelope stays — the portal must never offer a
     * "Review & Sign" action for a document that's already fully signed.
     */
    public function test_pending_envelopes_excludes_a_completed_envelope_but_keeps_the_other(): void
    {
        $matter = $this->tenant['matter'];
        $this->tenant['envelope']->delete();

        $completedDocument = Document::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'matter_id' => $matter->id,
            'uploaded_by' => $this->tenant['owner']->id,
            'status' => 'completed',
        ]);
        $completedEnvelope = Envelope::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'document_id' => $completedDocument->id,
            'status' => 'completed',
            'sent_at' => now()->subDays(3),
            'completed_at' => now()->subDay(),
        ]);

        $pendingDocument = Document::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'matter_id' => $matter->id,
            'uploaded_by' => $this->tenant['owner']->id,
            'status' => 'sent',
        ]);
        $pendingEnvelope = Envelope::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'document_id' => $pendingDocument->id,
            'status' => 'sent',
            'sent_at' => now()->subHour(),
        ]);

        $response = $this->actingAs($this->tenant['clientUser'])
            ->getJson("/api/v1/portal/matters/{$matter->public_id}");

        $response->assertOk();

        $envelopeIds = array_column($response->json('data.pending_envelopes'), 'envelope_id');
        $this->assertContains($pendingEnvelope->public_id, $envelopeIds);
        $this->assertNotContains($completedEnvelope->public_id, $envelopeIds);
        $this->assertCount(1, $envelopeIds);
    }
}
