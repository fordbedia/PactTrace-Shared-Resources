<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Tests;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Ports\ESignatureProvider;
use PactTrackSDK\SharedResources\Modules\Signature\Infrastructure\Fake\FakeSignatureProvider;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\TestCase\Extras\LoadsModuleApiRoutes;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/** Add/remove signers only while the envelope has NOT been submitted. */
class DraftSignerEditingTest extends BaseTest
{
    use LoadsModuleApiRoutes;

    private TestScenarioCollection $tenant;

    private FakeSignatureProvider $docusign;

    private Document $document;

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
        $this->tenant = ProviderTenantScenario::make('draft-signer-editing');

        $this->document = Document::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
        ]);
        Storage::disk('documents-test')->put($this->document->s3_path, 'pdf');
    }

    public function test_a_signer_can_be_added_then_removed_before_submission(): void
    {
        $envelope = $this->draft();
        $url = "/api/signature/documents/{$this->document->id}/draft-signers";

        $this->actingAs($this->tenant['owner'])
            ->postJson($url, ['name' => 'Dana Guest', 'email' => 'dana@example.test'])
            ->assertOk()
            ->assertJsonPath('signers.0.email', 'dana@example.test');

        $this->assertDatabaseHas('signers', ['envelope_id' => $envelope->id, 'email' => 'dana@example.test']);
        $this->assertCount(2, $this->docusign->recipients[$envelope->provider_envelope_id]);

        $this->actingAs($this->tenant['owner'])
            ->deleteJson($url, ['email' => 'dana@example.test'])
            ->assertOk()
            ->assertJsonCount(0, 'signers');

        $this->assertDatabaseMissing('signers', ['envelope_id' => $envelope->id, 'email' => 'dana@example.test']);
        $this->assertCount(1, $this->docusign->recipients[$envelope->provider_envelope_id]);
    }

    public function test_nothing_can_be_added_or_removed_once_submitted(): void
    {
        $envelope = $this->draft();
        $url = "/api/signature/documents/{$this->document->id}/draft-signers";
        $this->actingAs($this->tenant['owner'])->postJson($url, ['name' => 'Dana', 'email' => 'dana@example.test'])->assertOk();

        // Submitted on DocuSign's side (our webhook hasn't landed yet).
        $this->docusign->envelopeStatus = 'sent';

        $this->actingAs($this->tenant['owner'])->postJson($url, ['name' => 'Eve', 'email' => 'eve@example.test'])
            ->assertStatus(409)->assertJsonPath('submitted', true);
        $this->actingAs($this->tenant['owner'])->deleteJson($url, ['email' => 'dana@example.test'])
            ->assertStatus(409);

        $this->assertDatabaseHas('signers', ['envelope_id' => $envelope->id, 'email' => 'dana@example.test']);
        $this->assertDatabaseMissing('signers', ['email' => 'eve@example.test']);
    }

    public function test_the_primary_client_can_be_neither_duplicated_nor_removed(): void
    {
        $this->draft();
        $url = "/api/signature/documents/{$this->document->id}/draft-signers";
        $clientEmail = $this->tenant['client']->email;

        $this->actingAs($this->tenant['owner'])->postJson($url, ['name' => 'Dup', 'email' => $clientEmail])->assertStatus(422);
        $this->actingAs($this->tenant['owner'])->deleteJson($url, ['email' => $clientEmail])->assertStatus(422);
    }

    public function test_another_tenant_cannot_edit_signers(): void
    {
        $this->draft();
        $other = ProviderTenantScenario::make('draft-signer-editing-other');

        $this->actingAs($other['owner'])
            ->postJson("/api/signature/documents/{$this->document->id}/draft-signers", ['name' => 'X', 'email' => 'x@example.test'])
            ->assertForbidden();
    }

    private function draft(): Envelope
    {
        $this->docusign->envelopeStatus = 'created';
        $this->actingAs($this->tenant['owner'])
            ->postJson("/api/signature/documents/{$this->document->id}/prepare")->assertSuccessful();
        Cache::flush();

        return Envelope::query()->where('document_id', $this->document->id)->firstOrFail();
    }
}
