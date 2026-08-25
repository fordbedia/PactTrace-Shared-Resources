<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Tests;

use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\SanctumServiceProvider;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\Matter\Models\Matter;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Enums\EnvelopeStatus;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Ports\ESignatureProvider;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\ValueObjects\EnvelopeRecipient;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\ValueObjects\SigningToken;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\ValueObjects\WebhookEvent;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Signer;
use PactTrackSDK\SharedResources\TestCase\Extras\LoadsModuleApiRoutes;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;
use RuntimeException;

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

    private const DISK = 'documents-test';

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

        Storage::fake(self::DISK);
        config(['filesystems.document_disk' => self::DISK]);

        $this->tenant = ProviderTenantScenario::make('envelope-detail');
    }

    public function test_it_returns_the_matters_sole_envelope_with_no_disambiguator(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $response = $this->getJson("/api/v1/signature/matters/{$this->tenant['matter']->public_id}/envelope");

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
            "/api/v1/signature/matters/{$this->tenant['matter']->public_id}/envelope?envelope={$secondEnvelope->public_id}"
        );

        $response->assertOk()->assertJsonPath('data.id', $secondEnvelope->public_id);

        $original = $this->getJson(
            "/api/v1/signature/matters/{$this->tenant['matter']->public_id}/envelope?envelope={$this->tenant['envelope']->public_id}"
        );
        $original->assertOk()->assertJsonPath('data.id', $this->tenant['envelope']->public_id);
    }

    public function test_it_404s_for_a_matter_with_no_envelope_at_all(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $response = $this->getJson("/api/v1/signature/matters/{$this->tenant['otherMatter']->public_id}/envelope");

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
            "/api/v1/signature/matters/{$this->tenant['matter']->public_id}/envelope?envelope={$foreignEnvelope->public_id}"
        );

        $response->assertStatus(404);
    }

    public function test_it_rejects_a_matter_belonging_to_a_different_provider(): void
    {
        $otherTenant = ProviderTenantScenario::make('envelope-detail-other');
        Sanctum::actingAs($otherTenant['owner']);

        $response = $this->getJson("/api/v1/signature/matters/{$this->tenant['matter']->public_id}/envelope");

        $response->assertStatus(403);
    }

    public function test_it_requires_authentication(): void
    {
        $response = $this->getJson("/api/v1/signature/matters/{$this->tenant['matter']->public_id}/envelope");

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

        $response = $this->getJson("/api/v1/signature/matters/{$this->tenant['matter']->public_id}/envelope");

        $response->assertOk();
        $signers = collect($response->json('data.signers'));
        $guest = $signers->firstWhere('email', 'guest@example.com');

        $this->assertNotNull($guest);
        $this->assertTrue($guest['is_guest']);
    }

    /**
     * "Prepare All for Signature" on the Matter Detail page — see
     * .claude/rules/matter.md. Uses a brand-new Matter/documents rather than
     * $this->tenant['matter'], whose own fixture document carries an
     * envelope in a randomized status (EnvelopeFactory) — collision-prone
     * for an exact-count assertion here.
     */
    public function test_prepare_all_creates_one_envelope_per_eligible_document(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $matter = $this->freshMatter();
        $documentOne = $this->freshPdfDocument($matter);
        $documentTwo = $this->freshPdfDocument($matter);
        $documentThree = $this->freshPdfDocument($matter);

        $response = $this->postJson("/api/v1/signature/matters/{$matter->public_id}/prepare-all-envelopes");

        $response->assertOk();
        $this->assertCount(3, $response->json('prepared'));
        $this->assertCount(0, $response->json('skipped'));

        foreach ([$documentOne, $documentTwo, $documentThree] as $document) {
            $this->assertSame(1, Envelope::query()->where('document_id', $document->id)->count());
        }

        // All three share one batch id — see RecordSignatureCompletionUseCase,
        // "the one sanctioned exception to never-batched".
        $batchIds = Envelope::query()
            ->whereIn('document_id', [$documentOne->id, $documentTwo->id, $documentThree->id])
            ->pluck('batch_id')
            ->unique();
        $this->assertCount(1, $batchIds);
        $this->assertNotNull($batchIds->first());
    }

    public function test_prepare_all_skips_a_document_that_already_has_an_active_envelope(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $matter = $this->freshMatter();
        $alreadyActive = $this->freshPdfDocument($matter);
        Envelope::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'document_id' => $alreadyActive->id,
            'status' => EnvelopeStatus::Sent,
        ]);
        $eligible = $this->freshPdfDocument($matter);

        $response = $this->postJson("/api/v1/signature/matters/{$matter->public_id}/prepare-all-envelopes");

        $response->assertOk();
        $this->assertCount(1, $response->json('prepared'));
        $this->assertSame($eligible->id, $response->json('prepared.0.document_id'));
        $this->assertCount(1, $response->json('skipped'));
        $this->assertSame($alreadyActive->id, $response->json('skipped.0.document_id'));

        // The already-active envelope was never touched — still exactly one
        // envelope on that document, the original.
        $this->assertSame(1, Envelope::query()->where('document_id', $alreadyActive->id)->count());
    }

    /**
     * A document whose only envelope is voided is NOT "active" — re-preparing
     * after a void is an existing, supported flow on the single-document
     * path (see .claude/rules/signature.md), and "Prepare All" must not
     * silently skip it.
     */
    public function test_prepare_all_re_prepares_a_document_whose_only_envelope_was_voided(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $matter = $this->freshMatter();
        $document = $this->freshPdfDocument($matter);
        Envelope::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'document_id' => $document->id,
            'status' => EnvelopeStatus::Voided,
        ]);

        $response = $this->postJson("/api/v1/signature/matters/{$matter->public_id}/prepare-all-envelopes");

        $response->assertOk();
        $this->assertCount(1, $response->json('prepared'));
        $this->assertCount(0, $response->json('skipped'));
        $this->assertSame(2, Envelope::query()->where('document_id', $document->id)->count());
    }

    /**
     * The bug report this covers: PrepareEnvelopeForSignature::handle()
     * creates the DocuSign envelope in `draft` status immediately — before
     * the tenant has tagged anything or clicked Send in the Sender View
     * iframe — so an interrupted "Prepare All" (closed tab, "I'll do the
     * rest later") leaves draft-only envelopes behind. Those must NOT be
     * treated as "already active" (see PrepareMatterEnvelopesForSignature's
     * own docblock) — re-running "Prepare All" has to resume them via the
     * existing reusableDraftFor() idempotency, not permanently report
     * "Nothing to prepare" with no way back into DocuSign's editor.
     */
    public function test_prepare_all_resumes_a_document_whose_only_envelope_is_an_abandoned_draft(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $matter = $this->freshMatter();
        $document = $this->freshPdfDocument($matter);
        $existing = Envelope::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'document_id' => $document->id,
            'provider' => 'docusign',
            'provider_envelope_id' => 'existing-draft-envelope',
            'status' => EnvelopeStatus::Draft,
        ]);

        $this->bindResumableDraftProvider('existing-draft-envelope');

        $response = $this->postJson("/api/v1/signature/matters/{$matter->public_id}/prepare-all-envelopes");

        $response->assertOk();
        $this->assertCount(1, $response->json('prepared'));
        $this->assertCount(0, $response->json('skipped'));
        $this->assertSame($existing->public_id, $response->json('prepared.0.envelope_id'));

        // Reused, not duplicated — still exactly one envelope on this document.
        $this->assertSame(1, Envelope::query()->where('document_id', $document->id)->count());
    }

    /**
     * PrepareAllSignatureModal's signer-collection step submits `signers` as
     * a JSON object keyed by document id (see PrepareMatterEnvelopesRequest)
     * — this asserts that map actually reaches each document's own
     * PrepareEnvelopeForSignature::handle() call, a document omitted from
     * the map gets no co-signers (the pre-existing default), and one
     * document's co-signers never leak onto another's envelope.
     */
    public function test_prepare_all_applies_co_signers_per_document(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $matter = $this->freshMatter();
        $withCoSigner = $this->freshPdfDocument($matter);
        $withoutCoSigner = $this->freshPdfDocument($matter);

        $response = $this->postJson("/api/v1/signature/matters/{$matter->public_id}/prepare-all-envelopes", [
            'signers' => [
                (string) $withCoSigner->id => [
                    ['name' => 'Jamie Co-Signer', 'email' => 'jamie@example.com'],
                ],
            ],
        ]);

        $response->assertOk();
        $this->assertCount(2, $response->json('prepared'));

        $withCoSignerEnvelope = Envelope::query()->where('document_id', $withCoSigner->id)->firstOrFail();
        $withoutCoSignerEnvelope = Envelope::query()->where('document_id', $withoutCoSigner->id)->firstOrFail();

        $this->assertSame(
            ['Jamie Co-Signer'],
            $withCoSignerEnvelope->signers()->where('email', 'jamie@example.com')->pluck('name')->all()
        );
        // The client plus the one co-signer — never leaked onto the other document.
        $this->assertSame(2, $withCoSignerEnvelope->signers()->count());
        $this->assertSame(1, $withoutCoSignerEnvelope->signers()->count());
    }

    public function test_prepare_all_rejects_a_malformed_signers_payload(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $matter = $this->freshMatter();
        $document = $this->freshPdfDocument($matter);

        $response = $this->postJson("/api/v1/signature/matters/{$matter->public_id}/prepare-all-envelopes", [
            'signers' => [
                (string) $document->id => [
                    ['name' => 'Missing Email'],
                ],
            ],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(["signers.{$document->id}.0.email"]);
        $this->assertSame(0, Envelope::query()->where('document_id', $document->id)->count());
    }

    /**
     * A fake ESignatureProvider whose fetchEnvelopeStatus() confirms the
     * given provider_envelope_id is still DocuSign's own "created" (draft)
     * status — the live check PrepareEnvelopeForSignature::handle() makes
     * before reusing a locally-draft envelope. The shared FakeSignatureProvider
     * (bound app-wide by BaseTest) hardcodes fetchEnvelopeStatus() to
     * 'sent', which would make every reuse attempt look like the envelope
     * was already sent — this local rebind is what actually exercises the
     * resumable-draft path, same pattern ReconcileStaleEnvelopesTest uses
     * for the same reason.
     */
    private function bindResumableDraftProvider(string $providerEnvelopeId): void
    {
        $this->app->bind(ESignatureProvider::class, function () use ($providerEnvelopeId) {
            return new class($providerEnvelopeId) implements ESignatureProvider {
                public function __construct(private readonly string $providerEnvelopeId)
                {
                }

                public function createDraftEnvelope(string $title, string $fileName, string $fileContents, array $recipients, ?string $externalId = null): string
                {
                    throw new RuntimeException('unused — a reused draft must never create a new envelope');
                }

                public function senderViewUrl(string $providerEnvelopeId, string $returnUrl): string
                {
                    return "https://fake.docusign.test/sender/{$providerEnvelopeId}";
                }

                public function recipientViewUrl(string $providerEnvelopeId, EnvelopeRecipient $recipient, string $returnUrl, string $recipientId): SigningToken
                {
                    throw new RuntimeException('unused');
                }

                public function applyBrand(string $providerEnvelopeId, ?string $brandId): void
                {
                    throw new RuntimeException('unused — branding only runs on the brand-new-envelope branch');
                }

                public function fetchEnvelopeStatus(string $providerEnvelopeId): string
                {
                    if ($providerEnvelopeId !== $this->providerEnvelopeId) {
                        throw new RuntimeException("Unexpected fetchEnvelopeStatus call for [{$providerEnvelopeId}].");
                    }

                    return 'created';
                }

                public function verifyWebhookSignature(string $rawPayload, ?string $signatureHeader): bool
                {
                    return true;
                }

                public function normalizeWebhookEvent(array $payload): WebhookEvent
                {
                    throw new RuntimeException('unused');
                }
            };
        });
    }

    private function freshMatter(): Matter
    {
        return Matter::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
        ]);
    }

    private function freshPdfDocument(Matter $matter): Document
    {
        $document = Document::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $matter->client_id,
            'matter_id' => $matter->id,
        ]);
        Storage::disk(self::DISK)->put($document->s3_path, 'pdf-bytes');

        return $document;
    }
}
