<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Messaging\Tests;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\SanctumServiceProvider;
use PactTrackSDK\SharedResources\Modules\Messaging\Events\NewMessage;
use PactTrackSDK\SharedResources\Modules\Messaging\Http\Requests\SendMessageRequest;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\MessageThread;
use PactTrackSDK\SharedResources\TestCase\Extras\LoadsModuleApiRoutes;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * Provider-side HTTP surface for messaging — POST /api/v1/messages (start a
 * matter-scoped thread), POST /api/v1/messages/threads/{thread} (reply,
 * gated to the thread's own staff_user_id) and
 * GET /api/v1/matters/{matter}/messages.
 *
 * Routes are on real `auth:sanctum`, which BaseTest's harness does not
 * configure — so this registers SanctumServiceProvider itself and
 * authenticates with Sanctum::actingAs(), per EnvelopeDetailControllerTest.
 */
class MessageControllerTest extends BaseTest
{
    use LoadsModuleApiRoutes;

    private const DISK = 'documents-test';

    private TestScenarioCollection $tenant;

    private TestScenarioCollection $otherTenant;

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

        Event::fake([NewMessage::class]);

        $this->tenant = ProviderTenantScenario::make('msg-http-a');
        $this->otherTenant = ProviderTenantScenario::make('msg-http-b');
    }

    /* ── auth ──────────────────────────────────────────────────────────── */

    public function test_posting_a_message_requires_authentication(): void
    {
        $this->postJson('/api/v1/messages', [
            'matter_id' => $this->tenant['matter']->id,
            'subject' => 'x',
            'body' => 'hi',
        ])->assertUnauthorized();
    }

    /* ── store: matter-first ───────────────────────────────────────────── */

    public function test_an_owner_can_start_a_conversation_on_a_matter(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $response = $this->postJson('/api/v1/messages', [
            'matter_id' => $this->tenant['matter']->id,
            'subject' => 'Kickoff',
            'body' => 'Welcome aboard — first note here.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.body', 'Welcome aboard — first note here.')
            ->assertJsonPath('data.sender_id', $this->tenant['owner']->id);

        $this->assertDatabaseHas('message_threads', [
            'provider_id' => $this->tenant['provider']->id,
            'matter_id' => $this->tenant['matter']->id,
            'client_id' => $this->tenant['matter']->client_id,
            'staff_user_id' => $this->tenant['owner']->id,
            'subject' => 'Kickoff',
        ]);
        Event::assertDispatched(NewMessage::class);
    }

    public function test_starting_a_thread_requires_a_matter_and_a_subject(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $this->postJson('/api/v1/messages', ['body' => 'no scope'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['matter_id', 'subject']);

        $this->assertDatabaseCount('messages', 0);
    }

    public function test_the_matters_own_client_is_always_the_threads_client(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        // There is no `client_id` field on this endpoint anymore — the
        // matter's own client is the only possible value. A disagreeing
        // pair is structurally impossible to submit.
        $this->postJson('/api/v1/messages', [
            'matter_id' => $this->tenant['matter']->id,
            'subject' => 'On the matter',
            'body' => 'On the matter.',
        ])->assertCreated();

        $this->assertDatabaseHas('message_threads', [
            'matter_id' => $this->tenant['matter']->id,
            'client_id' => $this->tenant['matter']->client_id,
        ]);
    }

    public function test_a_provider_cannot_start_a_thread_on_another_providers_matter(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $this->postJson('/api/v1/messages', [
            'matter_id' => $this->otherTenant['matter']->id,
            'subject' => 'nope',
            'body' => 'should be blocked',
        ])->assertForbidden();

        $this->assertDatabaseCount('messages', 0);
    }

    /* ── reply: gated to the thread's staff member ─────────────────────── */

    public function test_the_threads_own_staff_member_can_reply(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $thread = MessageThread::factory()
            ->forMatter($this->tenant['matter'], $this->tenant['owner'])
            ->create();

        $this->postJson("/api/v1/messages/threads/{$thread->id}", ['body' => 'replying'])
            ->assertCreated()
            ->assertJsonPath('data.sender_id', $this->tenant['owner']->id);

        $this->assertDatabaseHas('messages', ['thread_id' => $thread->id, 'body' => 'replying']);
    }

    public function test_another_staffer_may_view_the_thread_but_not_reply_into_it(): void
    {
        $thread = MessageThread::factory()
            ->forMatter($this->tenant['matter'], $this->tenant['owner'])
            ->create();
        $thread->messages()->create(['sender_id' => $this->tenant['owner']->id, 'body' => 'seed']);

        Sanctum::actingAs($this->tenant['staff']);

        // Continuity: any staffer in the provider can read it.
        $this->getJson("/api/v1/messages/threads/{$thread->id}")->assertOk();

        // But only the designated staff_user_id may send into it.
        $this->postJson("/api/v1/messages/threads/{$thread->id}", ['body' => 'not my thread'])
            ->assertForbidden();

        $this->assertDatabaseMissing('messages', ['body' => 'not my thread']);
    }

    public function test_replying_across_tenants_is_forbidden(): void
    {
        $foreign = MessageThread::factory()
            ->forMatter($this->otherTenant['matter'], $this->otherTenant['owner'])
            ->create();

        Sanctum::actingAs($this->tenant['owner']);

        $this->postJson("/api/v1/messages/threads/{$foreign->id}", ['body' => 'leak'])
            ->assertForbidden();
    }

    /* ── attachment size limit ─────────────────────────────────────────── */

    public function test_an_attachment_at_the_limit_is_accepted(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $atLimit = UploadedFile::fake()->create('brief.pdf', SendMessageRequest::MAX_ATTACHMENT_KB);

        $this->postJson('/api/v1/messages', [
            'matter_id' => $this->tenant['matter']->id,
            'subject' => 'See attached',
            'body' => 'See attached.',
            'attachments' => [$atLimit],
        ])->assertCreated();

        $this->assertDatabaseCount('message_attachments', 1);
    }

    public function test_an_attachment_over_five_megabytes_is_rejected(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $tooBig = UploadedFile::fake()->create('huge.pdf', SendMessageRequest::MAX_ATTACHMENT_KB + 1);

        $this->postJson('/api/v1/messages', [
            'matter_id' => $this->tenant['matter']->id,
            'subject' => 'See attached',
            'body' => 'See attached.',
            'attachments' => [$tooBig],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('attachments.0');

        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('message_attachments', 0);
    }

    /* ── indexForMatter ───────────────────────────────────────────────── */

    public function test_it_lists_a_matters_messages_oldest_first(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $thread = MessageThread::factory()
            ->forMatter($this->tenant['matter'], $this->tenant['owner'])
            ->create();
        $thread->messages()->create([
            'sender_id' => $this->tenant['owner']->id,
            'body' => 'first',
            'created_at' => now()->subHour(),
        ]);
        $thread->messages()->create([
            'sender_id' => $this->tenant['owner']->id,
            'body' => 'second',
        ]);

        $this->getJson("/api/v1/matters/{$this->tenant['matter']->getRouteKey()}/messages")
            ->assertOk()
            ->assertJsonPath('data.0.body', 'first')
            ->assertJsonPath('data.1.body', 'second');
    }

    public function test_it_will_not_list_another_providers_matter_messages(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $this->getJson("/api/v1/matters/{$this->otherTenant['matter']->getRouteKey()}/messages")
            ->assertForbidden();
    }
}
