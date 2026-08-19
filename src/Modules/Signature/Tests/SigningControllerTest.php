<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Tests;

use PactTrackSDK\SharedResources\Modules\Signature\Domain\Enums\EnvelopeStatus;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\TestCase\Extras\LoadsModuleApiRoutes;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * HTTP surface behind the client portal's embedded signing flow — see
 * .claude/rules/signature.md, "Flow B".
 */
class SigningControllerTest extends BaseTest
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

        $this->tenant = ProviderTenantScenario::make('signing-http');
    }

    /* ── pending ───────────────────────────────────────────────────────── */

    public function test_pending_requires_being_signed_in(): void
    {
        $this->getJson('/api/signature/pending')->assertStatus(401);
    }

    public function test_pending_lists_only_this_clients_open_envelopes(): void
    {
        Envelope::query()->delete();

        $mine = $this->envelope($this->tenant['client'], $this->tenant['document'], EnvelopeStatus::Sent);
        $this->envelope($this->tenant['otherClient'], $this->tenant['otherDocument'], EnvelopeStatus::Sent);

        $response = $this->actingAs($this->tenant['clientUser'])->getJson('/api/signature/pending');

        $response->assertOk();
        $this->assertSame([$mine->id], $response->json('data.*.envelope_id'));
    }

    public function test_pending_excludes_completed_envelopes(): void
    {
        Envelope::query()->delete();
        $this->envelope($this->tenant['client'], $this->tenant['document'], EnvelopeStatus::Completed);

        $response = $this->actingAs($this->tenant['clientUser'])->getJson('/api/signature/pending');

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    /* ── pending-next ──────────────────────────────────────────────────── */

    public function test_pending_next_reports_done_when_nothing_is_left(): void
    {
        Envelope::query()->delete();

        $this->actingAs($this->tenant['clientUser'])
            ->getJson('/api/signature/pending-next')
            ->assertOk()
            ->assertJsonPath('done', true);
    }

    public function test_pending_next_excludes_the_given_envelope(): void
    {
        Envelope::query()->delete();
        $justSigned = $this->envelope($this->tenant['client'], $this->tenant['document'], EnvelopeStatus::Sent, now()->subDay());
        $next = $this->envelope($this->tenant['client'], $this->tenant['document'], EnvelopeStatus::Sent, now()->subHour());

        $this->actingAs($this->tenant['clientUser'])
            ->getJson("/api/signature/pending-next?exclude={$justSigned->id}")
            ->assertOk()
            ->assertJsonPath('done', false)
            ->assertJsonPath('envelope_id', $next->id);
    }

    /* ── signing-token ─────────────────────────────────────────────────── */

    public function test_signing_token_requires_being_signed_in(): void
    {
        $envelope = $this->envelope($this->tenant['client'], $this->tenant['document'], EnvelopeStatus::Sent);

        $this->postJson("/api/signature/envelopes/{$envelope->id}/signing-token")->assertStatus(401);
    }

    public function test_signing_token_returns_a_token_for_the_clients_own_envelope(): void
    {
        $envelope = $this->envelope($this->tenant['client'], $this->tenant['document'], EnvelopeStatus::Sent);

        $this->actingAs($this->tenant['clientUser'])
            ->postJson("/api/signature/envelopes/{$envelope->id}/signing-token")
            ->assertOk()
            ->assertJsonStructure(['token', 'expires_at', 'signing_url']);
    }

    public function test_signing_token_refuses_another_clients_envelope(): void
    {
        $foreign = $this->envelope($this->tenant['otherClient'], $this->tenant['otherDocument'], EnvelopeStatus::Sent);

        $this->actingAs($this->tenant['clientUser'])
            ->postJson("/api/signature/envelopes/{$foreign->id}/signing-token")
            ->assertStatus(403);
    }

    public function test_signing_token_refuses_a_completed_envelope_with_422(): void
    {
        $envelope = $this->envelope($this->tenant['client'], $this->tenant['document'], EnvelopeStatus::Completed);

        $this->actingAs($this->tenant['clientUser'])
            ->postJson("/api/signature/envelopes/{$envelope->id}/signing-token")
            ->assertStatus(422);
    }

    private function envelope(
        $client,
        $document,
        EnvelopeStatus $status,
        $sentAt = null,
    ): Envelope {
        return Envelope::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $client->id,
            'document_id' => $document->id,
            'status' => $status,
            'provider_envelope_id' => 'docusign-env-' . $document->id . '-' . $status->value,
            'sent_at' => $sentAt ?? now(),
        ]);
    }
}
