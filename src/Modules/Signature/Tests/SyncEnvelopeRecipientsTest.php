<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Tests;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;
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
 * DocuSign is the source of truth for recipients while an envelope is a
 * draft — see SyncEnvelopeRecipients and .claude/rules/signature.md,
 * "Recipient sync".
 */
class SyncEnvelopeRecipientsTest extends BaseTest
{
    use LoadsModuleApiRoutes;

    private const DISK = 'documents-test';

    private TestScenarioCollection $tenant;

    private FakeSignatureProvider $docusign;

    protected function moduleApiRoutes(): array
    {
        return [__DIR__ . '/../routes/api.php'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(self::DISK);
        config(['filesystems.document_disk' => self::DISK]);
        Cache::flush();

        // One shared instance, so the recipients the test edits "inside
        // DocuSign" are the ones the use case reads back.
        $this->docusign = new FakeSignatureProvider();
        $this->app->instance(ESignatureProvider::class, $this->docusign);

        $this->tenant = ProviderTenantScenario::make('sync-recipients');
    }

    public function test_signers_added_in_docusign_persist_even_when_the_user_exits_without_submitting(): void
    {
        [$document, $envelope] = $this->draftWithCoSigner();
        $this->addRemoteSigner($envelope, '3', 'Dana Guest', 'dana@example.test');

        $this->actingAs($this->tenant['owner'])
            ->postJson("/api/signature/documents/{$document->id}/sync-recipients")
            ->assertOk()
            ->assertJsonPath('added', 1);

        $this->assertDatabaseHas('signers', [
            'envelope_id' => $envelope->id,
            'provider_signer_id' => '3',
            'email' => 'dana@example.test',
        ]);
        $this->assertSame(1, AuditLog::query()->where('action', 'envelope.signers_synced')->count());
    }

    public function test_signers_removed_in_docusign_disappear_locally_but_the_primary_never_does(): void
    {
        [$document, $envelope] = $this->draftWithCoSigner();
        $this->docusign->recipients[$envelope->provider_envelope_id] = array_values(array_filter(
            $this->docusign->recipients[$envelope->provider_envelope_id],
            fn (ProviderRecipient $r) => $r->recipientId === '1',
        ));

        $this->actingAs($this->tenant['owner'])
            ->postJson("/api/signature/documents/{$document->id}/sync-recipients")
            ->assertOk()
            ->assertJsonPath('removed', 1);

        $this->assertDatabaseMissing('signers', ['envelope_id' => $envelope->id, 'email' => 'cosigner@example.test']);
        $this->assertDatabaseHas('signers', ['envelope_id' => $envelope->id, 'provider_signer_id' => '1']);
    }

    public function test_the_primary_signer_is_untouched_even_if_docusign_reports_different_details(): void
    {
        [$document, $envelope] = $this->draftWithCoSigner();
        $original = $envelope->signers()->where('provider_signer_id', '1')->first();

        $recipients = $this->docusign->recipients[$envelope->provider_envelope_id];
        $recipients[0] = new ProviderRecipient('1', 'Renamed In DocuSign', 'other@example.test', 'signed', $recipients[0]->clientUserId);
        $this->docusign->recipients[$envelope->provider_envelope_id] = $recipients;

        $this->actingAs($this->tenant['owner'])
            ->postJson("/api/signature/documents/{$document->id}/sync-recipients")
            ->assertOk()
            ->assertJsonPath('added', 0)
            ->assertJsonPath('removed', 0);

        $primary = $envelope->signers()->where('provider_signer_id', '1')->first();
        $this->assertSame($original->name, $primary->name);
        $this->assertSame($original->email, $primary->email);
        $this->assertSame('pending', $primary->status);
    }

    public function test_sync_is_idempotent(): void
    {
        [$document, $envelope] = $this->draftWithCoSigner();
        $this->addRemoteSigner($envelope, '3', 'Dana Guest', 'dana@example.test');

        for ($i = 0; $i < 2; $i++) {
            $this->actingAs($this->tenant['owner'])
                ->postJson("/api/signature/documents/{$document->id}/sync-recipients")->assertOk();
        }

        $this->assertSame(1, $envelope->signers()->where('email', 'dana@example.test')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'envelope.signers_synced')->count());
    }

    public function test_remote_signers_are_promoted_to_embedded_recipients(): void
    {
        [$document, $envelope] = $this->draftWithCoSigner();
        $this->addRemoteSigner($envelope, '3', 'Dana Guest', 'dana@example.test');

        $this->actingAs($this->tenant['owner'])
            ->postJson("/api/signature/documents/{$document->id}/sync-recipients")->assertOk();

        $this->assertSame([['3' => 'dana@example.test']], $this->docusign->embedCalls);
    }

    public function test_a_docusign_failure_degrades_to_the_saved_copy_without_a_500(): void
    {
        [$document, $envelope] = $this->draftWithCoSigner();
        $this->docusign->recipientsFailure = new RuntimeException('DocuSign is down');

        $this->actingAs($this->tenant['owner'])
            ->getJson("/api/signature/documents/{$document->id}/prepare")
            ->assertOk()
            ->assertJsonPath('refresh_failed', true)
            ->assertJsonCount(2, 'signers');

        $this->actingAs($this->tenant['owner'])
            ->postJson("/api/signature/documents/{$document->id}/sync-recipients")
            ->assertOk()
            ->assertJsonPath('refresh_failed', true);
    }

    public function test_reopening_the_modal_refreshes_signers_from_docusign(): void
    {
        [$document, $envelope] = $this->draftWithCoSigner();
        $this->addRemoteSigner($envelope, '3', 'Dana Guest', 'dana@example.test');

        $this->actingAs($this->tenant['owner'])
            ->getJson("/api/signature/documents/{$document->id}/prepare")
            ->assertOk()
            ->assertJsonPath('refresh_failed', false)
            ->assertJsonCount(3, 'signers');
    }

    public function test_a_sent_envelope_is_not_synced(): void
    {
        [$document, $envelope] = $this->draftWithCoSigner();
        $this->addRemoteSigner($envelope, '3', 'Dana Guest', 'dana@example.test');
        $envelope->forceFill(['status' => 'sent'])->save();

        $this->actingAs($this->tenant['owner'])
            ->postJson("/api/signature/documents/{$document->id}/sync-recipients")
            ->assertOk()
            ->assertJsonPath('synced', false);

        $this->assertDatabaseMissing('signers', ['email' => 'dana@example.test']);
    }

    public function test_another_tenant_cannot_sync_this_documents_recipients(): void
    {
        [$document, $envelope] = $this->draftWithCoSigner();
        $this->addRemoteSigner($envelope, '3', 'Dana Guest', 'dana@example.test');
        $other = ProviderTenantScenario::make('sync-recipients-other');

        $this->actingAs($other['owner'])
            ->postJson("/api/signature/documents/{$document->id}/sync-recipients")
            ->assertForbidden();

        $this->assertDatabaseMissing('signers', ['email' => 'dana@example.test']);
    }

    /** @return array{0: Document, 1: Envelope} */
    private function draftWithCoSigner(): array
    {
        $document = Document::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
        ]);
        Storage::disk(self::DISK)->put($document->s3_path, 'pdf-bytes');

        $this->actingAs($this->tenant['owner'])
            ->postJson("/api/signature/documents/{$document->id}/prepare", [
                'signers' => [['name' => 'Co Signer', 'email' => 'cosigner@example.test']],
            ])->assertSuccessful();

        $envelope = Envelope::query()->where('document_id', $document->id)->firstOrFail();
        Cache::flush();

        return [$document, $envelope];
    }

    private function addRemoteSigner(Envelope $envelope, string $id, string $name, string $email): void
    {
        $this->docusign->recipients[$envelope->provider_envelope_id][] = new ProviderRecipient($id, $name, $email, 'pending', null);
    }
}
