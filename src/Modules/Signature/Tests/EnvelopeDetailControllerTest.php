<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Tests;

use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\SanctumServiceProvider;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Enums\EnvelopeStatus;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Signer;
use PactTrackSDK\SharedResources\TestCase\Extras\LoadsModuleApiRoutes;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * The envelope detail view's HTTP surface
 * (/dashboard/signatures/matter/{matterId}) — see .claude/rules/signature.md.
 *
 * These routes are the one surface in this module gated by real
 * `auth:sanctum` middleware rather than the ResolvesActingUser bypass (see
 * EnvelopeDetailController's own docblock) — BaseTest's shared harness only
 * configures the `web` guard (see the top-level CLAUDE.md, "Unit testing"),
 * so this class registers Laravel\Sanctum\SanctumServiceProvider itself and
 * authenticates via Sanctum::actingAs() rather than the plain actingAs()
 * every other controller test in this module uses. No other test class in
 * the codebase needed this yet because no other route was on `auth:sanctum`
 * and also had HTTP-level test coverage — MattersController's own
 * `auth:sanctum` routes have none today either (see .claude/rules/matter.md
 * and .claude/rules/client.md for the same gap on those modules' routes).
 */
class EnvelopeDetailControllerTest extends BaseTest
{
    use LoadsModuleApiRoutes;

    private TestScenarioCollection $tenant;

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), SanctumServiceProvider::class];
    }

    protected function moduleApiRoutes(): array
    {
        return [__DIR__ . '/../routes/api.php'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = ProviderTenantScenario::make('envelope-detail');
    }

    public function test_it_returns_the_matters_sole_envelope_with_no_disambiguator(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $response = $this->getJson("/api/v1/signature/matters/{$this->tenant['matter']->id}/envelope");

        $response->assertOk()
            ->assertJsonPath('data.id', $this->tenant['envelope']->public_id)
            ->assertJsonPath('data.matter.id', $this->tenant['matter']->id);
        $this->assertIsArray($response->json('audit_trail'));
    }

    public function test_it_resolves_a_specific_envelope_when_a_matter_has_more_than_one(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $secondDocument = Document::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'matter_id' => $this->tenant['matter']->id,
        ]);
        $secondEnvelope = Envelope::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'document_id' => $secondDocument->id,
        ]);

        $response = $this->getJson(
            "/api/v1/signature/matters/{$this->tenant['matter']->id}/envelope?envelope={$secondEnvelope->public_id}"
        );

        $response->assertOk()->assertJsonPath('data.id', $secondEnvelope->public_id);

        $original = $this->getJson(
            "/api/v1/signature/matters/{$this->tenant['matter']->id}/envelope?envelope={$this->tenant['envelope']->public_id}"
        );
        $original->assertOk()->assertJsonPath('data.id', $this->tenant['envelope']->public_id);
    }

    public function test_it_404s_for_a_matter_with_no_envelope_at_all(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $response = $this->getJson("/api/v1/signature/matters/{$this->tenant['otherMatter']->id}/envelope");

        $response->assertStatus(404);
    }

    public function test_it_404s_when_the_envelope_query_param_does_not_belong_to_this_matter(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        // A real envelope, but on a document belonging to a different
        // matter of the *same* provider — must not resolve here.
        $foreignEnvelope = Envelope::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['otherClient']->id,
            'document_id' => $this->tenant['otherDocument']->id,
        ]);

        $response = $this->getJson(
            "/api/v1/signature/matters/{$this->tenant['matter']->id}/envelope?envelope={$foreignEnvelope->public_id}"
        );

        $response->assertStatus(404);
    }

    public function test_it_rejects_a_matter_belonging_to_a_different_provider(): void
    {
        $otherTenant = ProviderTenantScenario::make('envelope-detail-other');
        Sanctum::actingAs($otherTenant['owner']);

        $response = $this->getJson("/api/v1/signature/matters/{$this->tenant['matter']->id}/envelope");

        $response->assertStatus(403);
    }

    public function test_it_requires_authentication(): void
    {
        $response = $this->getJson("/api/v1/signature/matters/{$this->tenant['matter']->id}/envelope");

        $response->assertStatus(401);
    }

    public function test_void_transitions_the_envelope_and_returns_the_updated_detail(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $envelope = Envelope::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'document_id' => $this->tenant['document']->id,
            'status' => EnvelopeStatus::Sent,
        ]);

        $response = $this->postJson("/api/v1/signature/envelopes/{$envelope->public_id}/void", [
            'reason' => 'Client requested changes',
        ]);

        $response->assertOk()->assertJsonPath('data.status', 'voided');

        $this->assertDatabaseHas('envelopes', ['id' => $envelope->id, 'status' => 'voided']);
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Envelope::class,
            'auditable_id' => $envelope->id,
            'action' => 'envelope.voided',
        ]);
    }

    public function test_void_refuses_a_terminal_envelope_with_a_409(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $envelope = Envelope::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'document_id' => $this->tenant['document']->id,
            'status' => EnvelopeStatus::Completed,
        ]);

        $response = $this->postJson("/api/v1/signature/envelopes/{$envelope->public_id}/void");

        $response->assertStatus(409);
        $this->assertDatabaseHas('envelopes', ['id' => $envelope->id, 'status' => 'completed']);
    }

    public function test_signer_status_and_guest_flag_are_included(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        Signer::factory()->create([
            'envelope_id' => $this->tenant['envelope']->id,
            'name' => 'Guest Co-Signer',
            'email' => 'guest@example.com',
            'status' => 'pending',
            'signing_token_hash' => hash('sha256', 'a-raw-token'),
        ]);

        $response = $this->getJson("/api/v1/signature/matters/{$this->tenant['matter']->id}/envelope");

        $response->assertOk();
        $signers = collect($response->json('data.signers'));
        $guest = $signers->firstWhere('email', 'guest@example.com');

        $this->assertNotNull($guest);
        $this->assertTrue($guest['is_guest']);
    }
}
