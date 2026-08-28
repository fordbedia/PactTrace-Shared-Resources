<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Messaging\Tests;

use Illuminate\Support\Facades\Event;
use PactTrackSDK\SharedResources\Modules\Messaging\Events\InboxUpdated;
use PactTrackSDK\SharedResources\Modules\Messaging\Events\NewMessage;
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

        $this->tenant = ProviderTenantScenario::make('portal-msg-a');
        $this->other = ProviderTenantScenario::make('portal-msg-b');
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

    /* ── staff directory ──────────────────────────────────────────────── */

    public function test_staff_directory_lists_only_this_providers_provider_side_users(): void
    {
        $response = $this->actingAs($this->tenant['clientUser'])
            ->getJson("/api/v1/portal/matters/{$this->matterKey()}/staff-directory");

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($this->tenant['owner']->id, $ids);
        $this->assertContains($this->tenant['staff']->id, $ids);
        $this->assertNotContains($this->tenant['clientUser']->id, $ids, 'a client is never in the directory');
        $this->assertNotContains($this->other['owner']->id, $ids, 'no cross-tenant staff');
        $this->assertNotContains($this->other['staff']->id, $ids);

        // Allow-list: no email leaks.
        $this->assertArrayNotHasKey('email', $response->json('data.0'));
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
}
