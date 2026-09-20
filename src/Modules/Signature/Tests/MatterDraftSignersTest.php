<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Tests;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\SanctumServiceProvider;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Ports\ESignatureProvider;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\ValueObjects\ProviderRecipient;
use PactTrackSDK\SharedResources\Modules\Signature\Infrastructure\Fake\FakeSignatureProvider;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\TestCase\Extras\LoadsModuleApiRoutes;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;
use RuntimeException;

/**
 * The bulk "Prepare All" modal's signer memory: signers added inside
 * DocuSign to a matter's draft envelopes are synced and shown on reopen
 * instead of "None" — see EnvelopeDetailController::draftSigners().
 */
class MatterDraftSignersTest extends BaseTest
{
    use LoadsModuleApiRoutes;

    private TestScenarioCollection $tenant;

    private FakeSignatureProvider $docusign;

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

        Storage::fake('documents-test');
        config(['filesystems.document_disk' => 'documents-test']);
        Cache::flush();

        $this->docusign = new FakeSignatureProvider();
        $this->app->instance(ESignatureProvider::class, $this->docusign);
        $this->tenant = ProviderTenantScenario::make('matter-draft-signers');
    }

    public function test_signers_added_in_docusign_show_up_when_the_bulk_modal_reopens(): void
    {
        [$document, $envelope] = $this->draft();
        $this->docusign->recipients[$envelope->provider_envelope_id][] =
            new ProviderRecipient('2', 'Dana Guest', 'dana@example.test', 'pending', null);

        Sanctum::actingAs($this->tenant['owner']);
        $response = $this->getJson("/api/v1/signature/matters/{$this->tenant['matter']->public_id}/draft-signers");

        $response->assertOk()
            ->assertJsonPath("documents.{$document->id}.refresh_failed", false)
            ->assertJsonCount(1, "documents.{$document->id}.signers")
            ->assertJsonPath("documents.{$document->id}.signers.0.email", 'dana@example.test');
    }

    public function test_the_primary_client_is_never_listed_as_an_additional_signer(): void
    {
        [$document] = $this->draft();

        Sanctum::actingAs($this->tenant['owner']);
        $this->getJson("/api/v1/signature/matters/{$this->tenant['matter']->public_id}/draft-signers")
            ->assertOk()
            ->assertJsonCount(0, "documents.{$document->id}.signers");
    }

    public function test_the_forced_sync_endpoint_persists_signers_and_a_docusign_failure_degrades(): void
    {
        [$document, $envelope] = $this->draft();
        $this->docusign->recipients[$envelope->provider_envelope_id][] =
            new ProviderRecipient('2', 'Dana Guest', 'dana@example.test', 'pending', null);
        Sanctum::actingAs($this->tenant['owner']);

        $this->postJson("/api/v1/signature/matters/{$this->tenant['matter']->public_id}/sync-draft-signers")->assertOk();
        $this->assertDatabaseHas('signers', ['envelope_id' => $envelope->id, 'email' => 'dana@example.test']);

        $this->docusign->recipientsFailure = new RuntimeException('down');
        $this->postJson("/api/v1/signature/matters/{$this->tenant['matter']->public_id}/sync-draft-signers")
            ->assertOk()
            ->assertJsonPath("documents.{$document->id}.refresh_failed", true)
            ->assertJsonCount(1, "documents.{$document->id}.signers");
    }

    public function test_another_tenant_cannot_read_this_matters_draft_signers(): void
    {
        $this->draft();
        $other = ProviderTenantScenario::make('matter-draft-signers-other');

        Sanctum::actingAs($other['owner']);
        $this->getJson("/api/v1/signature/matters/{$this->tenant['matter']->public_id}/draft-signers")
            ->assertForbidden();
    }

    /** @return array{0: Document, 1: Envelope} */
    private function draft(): array
    {
        $document = Document::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'matter_id' => $this->tenant['matter']->id,
            'client_id' => $this->tenant['client']->id,
        ]);
        Storage::disk('documents-test')->put($document->s3_path, 'pdf');

        Sanctum::actingAs($this->tenant['owner']);
        $this->postJson("/api/v1/signature/matters/{$this->tenant['matter']->public_id}/prepare-all-envelopes")->assertOk();
        Cache::flush();

        return [$document, Envelope::query()->where('document_id', $document->id)->firstOrFail()];
    }
}
