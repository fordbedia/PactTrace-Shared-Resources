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
 * HTTP surface for in-portal messaging — POST /api/v1/messages and
 * GET /api/v1/matters/{matter}/messages.
 *
 * These routes are on real `auth:sanctum` middleware (like
 * EnvelopeDetailController), which BaseTest's shared harness does not
 * configure — so this class registers SanctumServiceProvider itself and
 * authenticates with Sanctum::actingAs(), per the same pattern
 * EnvelopeDetailControllerTest established.
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
            'client_id' => $this->tenant['client']->id,
            'body' => 'hi',
        ])->assertUnauthorized();
    }

    /* ── store ─────────────────────────────────────────────────────────── */

    public function test_an_owner_can_start_a_conversation_with_a_client(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $response = $this->postJson('/api/v1/messages', [
            'client_id' => $this->tenant['client']->id,
            'subject' => 'Kickoff',
            'body' => 'Welcome aboard — first note here.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.body', 'Welcome aboard — first note here.')
            ->assertJsonPath('data.sender_id', $this->tenant['owner']->id);

        $this->assertDatabaseHas('message_threads', [
            'provider_id' => $this->tenant['provider']->id,
            'client_id' => $this->tenant['client']->id,
            'subject' => 'Kickoff',
        ]);
        Event::assertDispatched(NewMessage::class);
    }

    public function test_a_provider_cannot_message_another_providers_client(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $this->postJson('/api/v1/messages', [
            'client_id' => $this->otherTenant['client']->id,
            'body' => 'should be blocked',
        ])->assertForbidden();

        $this->assertDatabaseCount('messages', 0);
    }

    public function test_when_a_matter_is_given_its_own_client_is_used(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        // Deliberately send a *different*, mismatched client_id alongside the
        // matter — the matter's own client must win (same rule as document
        // upload). `matter_id` in the body is the internal integer FK, same
        // as POST /api/documents (see .claude/rules/document.md); only the
        // GET route param binds by public_id.
        $response = $this->postJson('/api/v1/messages', [
            'client_id' => $this->tenant['otherClient']->id,
            'matter_id' => $this->tenant['matter']->id,
            'body' => 'On the matter.',
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('message_threads', [
            'matter_id' => $this->tenant['matter']->id,
            'client_id' => $this->tenant['matter']->client_id,
        ]);
    }

    /* ── attachment size limit ─────────────────────────────────────────── */

    public function test_an_attachment_at_the_limit_is_accepted(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $atLimit = UploadedFile::fake()->create('brief.pdf', SendMessageRequest::MAX_ATTACHMENT_KB);

        $this->postJson('/api/v1/messages', [
            'client_id' => $this->tenant['client']->id,
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
            'client_id' => $this->tenant['client']->id,
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

        $thread = MessageThread::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'client_id' => $this->tenant['client']->id,
            'matter_id' => $this->tenant['matter']->id,
        ]);
        $thread->messages()->create([
            'sender_id' => $this->tenant['owner']->id,
            'body' => 'first',
            'created_at' => now()->subHour(),
        ]);
        $thread->messages()->create([
            'sender_id' => $this->tenant['owner']->id,
            'body' => 'second',
        ]);

        $response = $this->getJson("/api/v1/matters/{$this->tenant['matter']->getRouteKey()}/messages");

        $response->assertOk()
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
