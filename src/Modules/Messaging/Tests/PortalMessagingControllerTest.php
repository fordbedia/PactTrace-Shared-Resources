<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Messaging\Tests;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use PactTrackSDK\SharedResources\Modules\Messaging\Events\InboxUpdated;
use PactTrackSDK\SharedResources\Modules\Messaging\Events\NewMessage;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\MessageAttachment;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\MessageThread;
use PactTrackSDK\SharedResources\TestCase\Extras\LoadsModuleApiRoutes;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * The client-portal messaging widget + staff contact directory
 * (PortalMessagingController). Portal routes use ResolvesActingUser +
 * Gate::forUser(), so plain actingAs() is enough — no Sanctum guard.
 */
class PortalMessagingControllerTest extends BaseTest
{
    use LoadsModuleApiRoutes;

    private const DISK = 'documents-test';

    private TestScenarioCollection $tenant;

    private TestScenarioCollection $other;

    protected function moduleApiRoutes(): array
    {
        return [__DIR__ . '/../routes/api.php'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([NewMessage::class, InboxUpdated::class]);
        Storage::fake(self::DISK);
        config(['filesystems.document_disk' => self::DISK]);

        $this->tenant = ProviderTenantScenario::make('portal-msg-a');
        $this->other = ProviderTenantScenario::make('portal-msg-b');
    }

    private function attachmentOnThread(MessageThread $thread, string $bytes, string $name = 'plan.pdf'): MessageAttachment
    {
        $message = $thread->messages()->create([
            'sender_id' => $this->tenant['owner']->id,
            'body' => 'see attached',
        ]);

        $path = 'message-attachments/' . uniqid('', true) . "-{$name}";
        Storage::disk(self::DISK)->put($path, $bytes);

        return MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'file_name' => $name,
            'mime_type' => 'application/pdf',
            'size' => strlen($bytes),
            's3_path' => $path,
        ]);
    }

    private function matterKey(): string
    {
        return $this->tenant['matter']->public_id;
    }

    /* ── thread list ──────────────────────────────────────────────────── */

    public function test_a_client_lists_only_their_own_matters_threads(): void
    {
        $onMatter = MessageThread::factory()
            ->forMatter($this->tenant['matter'], $this->tenant['owner'], 'Retainer')
            ->create();
        $onMatterToo = MessageThread::factory()
            ->forMatter($this->tenant['matter'], $this->tenant['staff'], 'Documents')
            ->create();

        // A thread on a different matter of the SAME provider — must not appear.
        MessageThread::factory()
            ->forMatter($this->tenant['otherMatter'], $this->tenant['owner'], 'Elsewhere')
            ->create();

        $response = $this->actingAs($this->tenant['clientUser'])
            ->getJson("/api/v1/portal/matters/{$this->matterKey()}/message-threads");

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$onMatter->id, $onMatterToo->id], $ids);
    }

    public function test_thread_list_requires_a_signed_in_client(): void
    {
        // Provider-side user has no `client` — 401.
        $this->actingAs($this->tenant['owner'])
            ->getJson("/api/v1/portal/matters/{$this->matterKey()}/message-threads")
            ->assertUnauthorized();
    }

    /* ── contact directory (owner + assigned staff, matter-scoped) ─────── */

    public function test_directory_for_a_matter_with_no_assigned_staff_returns_only_the_owner(): void
    {
        // ProviderTenantScenario's matter has no assigned_staff_id.
        $response = $this->actingAs($this->tenant['clientUser'])
            ->getJson("/api/v1/portal/matters/{$this->matterKey()}/staff-directory");

        $response->assertOk();

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($this->tenant['owner']->id, $data[0]['id']);
        $this->assertSame('owner', $data[0]['relationship']);

        // Allow-list: no email / title leak.
        $this->assertArrayNotHasKey('email', $data[0]);
        $this->assertArrayNotHasKey('title', $data[0]);
    }

    public function test_directory_for_a_matter_with_an_assigned_staff_returns_owner_and_that_staffer(): void
    {
        $this->tenant['matter']->forceFill([
            'assigned_staff_id' => $this->tenant['staff']->id,
        ])->save();

        $response = $this->actingAs($this->tenant['clientUser'])
            ->getJson("/api/v1/portal/matters/{$this->matterKey()}/staff-directory");

        $response->assertOk();

        $rows = collect($response->json('data'))->keyBy('id');
        $this->assertEqualsCanonicalizing(
            [$this->tenant['owner']->id, $this->tenant['staff']->id],
            $rows->keys()->all(),
        );
        $this->assertSame('owner', $rows[$this->tenant['owner']->id]['relationship']);
        $this->assertSame('assigned', $rows[$this->tenant['staff']->id]['relationship']);

        // Never another tenant's people.
        $ids = $rows->keys()->all();
        $this->assertNotContains($this->other['owner']->id, $ids);
        $this->assertNotContains($this->other['staff']->id, $ids);
    }

    public function test_directory_de_duplicates_when_the_assigned_staff_is_the_owner(): void
    {
        $this->tenant['matter']->forceFill([
            'assigned_staff_id' => $this->tenant['owner']->id,
        ])->save();

        $response = $this->actingAs($this->tenant['clientUser'])
            ->getJson("/api/v1/portal/matters/{$this->matterKey()}/staff-directory");

        $response->assertOk();

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($this->tenant['owner']->id, $data[0]['id']);
    }

    public function test_a_client_can_start_a_thread_from_a_directory_contact(): void
    {
        $this->tenant['matter']->forceFill([
            'assigned_staff_id' => $this->tenant['staff']->id,
        ])->save();

        $this->actingAs($this->tenant['clientUser'])
            ->postJson("/api/v1/portal/matters/{$this->matterKey()}/message-threads", [
                'staff_user_id' => $this->tenant['staff']->id,
                'subject' => 'Coverage question',
                'body' => 'Who should I contact while you are away?',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('message_threads', [
            'matter_id' => $this->tenant['matter']->id,
            'client_id' => $this->tenant['matter']->client_id,
            'staff_user_id' => $this->tenant['staff']->id,
            'subject' => 'Coverage question',
        ]);
    }

    /* ── client-initiated thread ──────────────────────────────────────── */

    public function test_a_client_starts_a_thread_with_a_chosen_staffer(): void
    {
        $response = $this->actingAs($this->tenant['clientUser'])
            ->postJson("/api/v1/portal/matters/{$this->matterKey()}/message-threads", [
                'staff_user_id' => $this->tenant['staff']->id,
                'subject' => 'Question about my invoice',
                'body' => 'Could you clarify the last line item?',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.sender_id', $this->tenant['clientUser']->id);

        $this->assertDatabaseHas('message_threads', [
            'provider_id' => $this->tenant['provider']->id,
            'matter_id' => $this->tenant['matter']->id,
            'client_id' => $this->tenant['matter']->client_id,
            'staff_user_id' => $this->tenant['staff']->id,
            'subject' => 'Question about my invoice',
        ]);
    }

    public function test_a_client_cannot_start_a_thread_with_a_staffer_from_another_provider(): void
    {
        $this->actingAs($this->tenant['clientUser'])
            ->postJson("/api/v1/portal/matters/{$this->matterKey()}/message-threads", [
                'staff_user_id' => $this->other['staff']->id,
                'subject' => 'Nope',
                'body' => 'should be rejected',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('staff_user_id');

        $this->assertDatabaseCount('message_threads', 0);
    }

    public function test_a_client_cannot_name_another_client_as_the_staffer(): void
    {
        // clientUser is a real users row carrying this provider_id, but a
        // client role — the directory query and this guard both exclude it.
        $this->actingAs($this->tenant['clientUser'])
            ->postJson("/api/v1/portal/matters/{$this->matterKey()}/message-threads", [
                'staff_user_id' => $this->tenant['clientUser']->id,
                'subject' => 'Nope',
                'body' => 'should be rejected',
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('message_threads', 0);
    }

    /* ── reply / isolation ────────────────────────────────────────────── */

    public function test_a_client_replies_into_their_own_thread(): void
    {
        $thread = MessageThread::factory()
            ->forMatter($this->tenant['matter'], $this->tenant['owner'])
            ->create();

        $this->actingAs($this->tenant['clientUser'])
            ->postJson("/api/v1/portal/message-threads/{$thread->id}", ['body' => 'thanks!'])
            ->assertCreated()
            ->assertJsonPath('data.sender_id', $this->tenant['clientUser']->id);

        $this->assertDatabaseHas('messages', ['thread_id' => $thread->id, 'body' => 'thanks!']);
    }

    public function test_a_client_reply_carries_attachments(): void
    {
        $thread = MessageThread::factory()
            ->forMatter($this->tenant['matter'], $this->tenant['owner'])
            ->create();

        $this->actingAs($this->tenant['clientUser'])
            ->post("/api/v1/portal/message-threads/{$thread->id}", [
                'body' => 'Here are my completed forms.',
                'attachments' => [
                    UploadedFile::fake()->create('form1.pdf', 120),
                    UploadedFile::fake()->create('form2.pdf', 120),
                ],
            ])
            ->assertCreated();

        $this->assertDatabaseCount('message_attachments', 2);
    }

    public function test_a_client_cannot_open_another_clients_thread(): void
    {
        $foreign = MessageThread::factory()
            ->forMatter($this->tenant['otherMatter'], $this->tenant['owner'])
            ->create();

        $this->actingAs($this->tenant['clientUser'])
            ->getJson("/api/v1/portal/message-threads/{$foreign->id}")
            ->assertForbidden();

        $this->actingAs($this->tenant['clientUser'])
            ->postJson("/api/v1/portal/message-threads/{$foreign->id}", ['body' => 'leak'])
            ->assertForbidden();
    }

    /* ── attachments ──────────────────────────────────────────────────── */

    public function test_thread_conversation_includes_attachment_metadata(): void
    {
        $thread = MessageThread::factory()
            ->forMatter($this->tenant['matter'], $this->tenant['owner'])
            ->create();
        $this->attachmentOnThread($thread, 'REPORT-BYTES', 'report.pdf');

        $this->actingAs($this->tenant['clientUser'])
            ->getJson("/api/v1/portal/message-threads/{$thread->id}")
            ->assertOk()
            ->assertJsonPath('data.messages.0.attachments.0.file_name', 'report.pdf')
            ->assertJsonPath('data.messages.0.attachments.0.mime_type', 'application/pdf')
            ->assertJsonPath('data.messages.0.attachments.0.size', strlen('REPORT-BYTES'))
            ->assertJsonMissingPath('data.messages.0.attachments.0.s3_path');
    }

    public function test_a_client_downloads_an_attachment_from_their_own_thread(): void
    {
        $thread = MessageThread::factory()
            ->forMatter($this->tenant['matter'], $this->tenant['owner'])
            ->create();
        $attachment = $this->attachmentOnThread($thread, 'CLIENT-VISIBLE-BYTES');

        $response = $this->actingAs($this->tenant['clientUser'])
            ->get("/api/v1/portal/message-attachments/{$attachment->id}");

        $response->assertOk();
        $this->assertSame('CLIENT-VISIBLE-BYTES', $response->streamedContent());
    }

    public function test_a_client_cannot_download_an_attachment_from_another_matters_thread(): void
    {
        $foreign = MessageThread::factory()
            ->forMatter($this->tenant['otherMatter'], $this->tenant['owner'])
            ->create();
        $attachment = $this->attachmentOnThread($foreign, 'SECRET-BYTES', 'secret.pdf');

        $this->actingAs($this->tenant['clientUser'])
            ->get("/api/v1/portal/message-attachments/{$attachment->id}")
            ->assertForbidden();
    }

    public function test_attachment_download_requires_a_signed_in_client(): void
    {
        $thread = MessageThread::factory()
            ->forMatter($this->tenant['matter'], $this->tenant['owner'])
            ->create();
        $attachment = $this->attachmentOnThread($thread, 'bytes');

        // Provider-side user has no `client` — 401, same as every portal route.
        $this->actingAs($this->tenant['owner'])
            ->get("/api/v1/portal/message-attachments/{$attachment->id}")
            ->assertUnauthorized();
    }
}
