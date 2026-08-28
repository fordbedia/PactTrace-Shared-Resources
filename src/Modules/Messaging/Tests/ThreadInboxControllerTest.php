<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Messaging\Tests;

use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\SanctumServiceProvider;
use PactTrackSDK\SharedResources\Modules\Messaging\Events\InboxUpdated;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\MessageThread;
use PactTrackSDK\SharedResources\TestCase\Extras\LoadsModuleApiRoutes;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * The /dashboard/messages inbox HTTP surface — GET /messages/threads
 * (All / Unread tabs), GET /messages/unread-count (sidebar badge + tab
 * pill), GET /messages/threads/{thread}, DELETE /messages/threads/{thread}
 * (archive = soft delete), POST /messages/threads/{thread}/read.
 *
 * Same harness shape as MessageControllerTest: these routes are on real
 * `auth:sanctum`, which BaseTest does not configure, so this class
 * registers SanctumServiceProvider itself and authenticates with
 * Sanctum::actingAs().
 */
class ThreadInboxControllerTest extends BaseTest
{
    use LoadsModuleApiRoutes;

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

        Event::fake([InboxUpdated::class]);

        $this->tenant = ProviderTenantScenario::make('inbox-a');
        $this->otherTenant = ProviderTenantScenario::make('inbox-b');
    }

    /**
     * A thread for the given tenant. `$inbound` messages count as "unread
     * for the owner" — they are sent by the client user, not the owner.
     */
    private function threadWith(
        TestScenarioCollection $tenant,
        int $inbound = 0,
        int $outbound = 0,
        bool $archived = false,
    ): MessageThread {
        $thread = MessageThread::factory()->create([
            'provider_id' => $tenant['provider']->id,
            'client_id' => $tenant['client']->id,
        ]);

        for ($i = 0; $i < $inbound; $i++) {
            $thread->messages()->create([
                'sender_id' => $tenant['clientUser']->id,
                'body' => "inbound {$i}",
                'read_at' => null,
            ]);
        }

        for ($i = 0; $i < $outbound; $i++) {
            $thread->messages()->create([
                'sender_id' => $tenant['owner']->id,
                'body' => "outbound {$i}",
            ]);
        }

        if ($archived) {
            $thread->archive();
        }

        return $thread;
    }

    /* ── auth ──────────────────────────────────────────────────────────── */

    public function test_listing_threads_requires_authentication(): void
    {
        $this->getJson('/api/v1/messages/threads')->assertUnauthorized();
    }

    public function test_unread_count_requires_authentication(): void
    {
        $this->getJson('/api/v1/messages/unread-count')->assertUnauthorized();
    }

    /* ── All tab ───────────────────────────────────────────────────────── */

    public function test_all_tab_returns_only_this_tenants_non_archived_threads(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $visible = $this->threadWith($this->tenant, inbound: 1);
        $this->threadWith($this->tenant, inbound: 1, archived: true);
        $this->threadWith($this->otherTenant, inbound: 1);

        $response = $this->getJson('/api/v1/messages/threads?filter=all');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->id)
            ->assertJsonPath('data.0.unread', true);
    }

    public function test_staff_can_list_threads(): void
    {
        Sanctum::actingAs($this->tenant['staff']);

        $this->threadWith($this->tenant, outbound: 1);

        $this->getJson('/api/v1/messages/threads?filter=all')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    /* ── Unread tab ────────────────────────────────────────────────────── */

    public function test_unread_tab_returns_only_threads_with_unread_and_drops_after_read(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $unread = $this->threadWith($this->tenant, inbound: 2);
        $this->threadWith($this->tenant, outbound: 3); // all sent by owner => nothing unread

        $this->getJson('/api/v1/messages/threads?filter=unread')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $unread->id);

        $this->postJson("/api/v1/messages/threads/{$unread->id}/read")->assertOk();

        $this->getJson('/api/v1/messages/threads?filter=unread')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /* ── unread-count ─────────────────────────────────────────────────── */

    public function test_unread_count_totals_threads_with_unread_for_the_user(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $a = $this->threadWith($this->tenant, inbound: 1);
        $this->threadWith($this->tenant, inbound: 3);
        $this->threadWith($this->tenant, outbound: 2);
        $this->threadWith($this->tenant, inbound: 1, archived: true); // archived never counts
        $this->threadWith($this->otherTenant, inbound: 5); // other tenant never counts

        $this->getJson('/api/v1/messages/unread-count')
            ->assertOk()
            ->assertJsonPath('count', 2);

        $this->postJson("/api/v1/messages/threads/{$a->id}/read")->assertOk();

        $this->getJson('/api/v1/messages/unread-count')
            ->assertOk()
            ->assertJsonPath('count', 1);
    }

    /* ── archive ──────────────────────────────────────────────────────── */

    public function test_archiving_a_thread_soft_deletes_it_and_removes_it_from_both_tabs_same_cycle(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $thread = $this->threadWith($this->tenant, inbound: 1);

        $this->deleteJson("/api/v1/messages/threads/{$thread->id}")->assertNoContent();

        // Soft, not hard: the row and its messages survive for the audit trail.
        $this->assertSoftDeleted('message_threads', ['id' => $thread->id]);
        $this->assertNotNull(MessageThread::withTrashed()->find($thread->id));
        $this->assertDatabaseCount('messages', 1);

        $this->getJson('/api/v1/messages/threads?filter=all')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/messages/threads?filter=unread')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/messages/unread-count')
            ->assertOk()->assertJsonPath('count', 0);

        Event::assertDispatched(InboxUpdated::class);
    }

    public function test_archiving_another_tenants_thread_is_forbidden(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $foreign = $this->threadWith($this->otherTenant, inbound: 1);

        $this->deleteJson("/api/v1/messages/threads/{$foreign->id}")->assertForbidden();

        $this->assertNotSoftDeleted('message_threads', ['id' => $foreign->id]);
    }

    /* ── showThread ───────────────────────────────────────────────────── */

    public function test_show_thread_returns_the_conversation_oldest_first(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $thread = MessageThread::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'client_id' => $this->tenant['client']->id,
        ]);
        $thread->messages()->create([
            'sender_id' => $this->tenant['owner']->id,
            'body' => 'first',
            'created_at' => now()->subHour(),
        ]);
        $thread->messages()->create([
            'sender_id' => $this->tenant['clientUser']->id,
            'body' => 'second',
        ]);

        $this->getJson("/api/v1/messages/threads/{$thread->id}")
            ->assertOk()
            ->assertJsonPath('data.messages.0.body', 'first')
            ->assertJsonPath('data.messages.1.body', 'second');
    }

    public function test_show_thread_404s_for_an_archived_thread(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $thread = $this->threadWith($this->tenant, inbound: 1, archived: true);

        $this->getJson("/api/v1/messages/threads/{$thread->id}")->assertNotFound();
    }

    public function test_show_thread_is_forbidden_across_tenants(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $foreign = $this->threadWith($this->otherTenant, inbound: 1);

        $this->getJson("/api/v1/messages/threads/{$foreign->id}")->assertForbidden();
    }
}
