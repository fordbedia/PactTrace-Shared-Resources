<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Client\Tests;

use PactTrackSDK\SharedResources\Modules\Matter\Models\Matter;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\Message;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\MessageThread;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\TestCase\Extras\LoadsModuleApiRoutes;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * `GET /api/v1/portal/notifications` — the client-portal notification bell's
 * live-computed summary. See .claude/rules/client.md, "Client portal
 * notification bell".
 */
class PortalNotificationControllerTest extends BaseTest
{
    use LoadsModuleApiRoutes;

    private TestScenarioCollection $tenant;

    protected function moduleApiRoutes(): array
    {
        return [__DIR__ . '/../routes/api.php'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = ProviderTenantScenario::make('portal-notif');

        // The scenario's baseline envelope has a random status — drop it so
        // each test controls exactly what's pending.
        $this->tenant['envelope']->delete();
    }

    public function test_an_unauthenticated_visitor_gets_404(): void
    {
        $this->getJson('/api/v1/portal/notifications')->assertStatus(404);
    }

    public function test_a_provider_side_user_gets_404(): void
    {
        $this->actingAs($this->tenant['staff'])
            ->getJson('/api/v1/portal/notifications')
            ->assertStatus(404);
    }

    public function test_it_counts_pending_signatures_new_matters_and_unread_threads_for_the_client(): void
    {
        $client = $this->tenant['client'];

        // 2 pending envelopes + 1 terminal (not counted).
        $this->envelopeFor($client->id, 'sent');
        $this->envelopeFor($client->id, 'partially_signed');
        $this->envelopeFor($client->id, 'completed');

        // The scenario already gives the client one freshly-created matter
        // (inside the 7-day window). Add an old one that must NOT count.
        Matter::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $client->id,
            'created_at' => now()->subDays(30),
        ]);

        // One thread with an unread staff message.
        $thread = MessageThread::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'client_id' => $client->id,
            'matter_id' => $this->tenant['matter']->id,
            'staff_user_id' => $this->tenant['owner']->id,
        ]);
        Message::factory()->create([
            'thread_id' => $thread->id,
            'sender_id' => $this->tenant['owner']->id,
            'read_at' => null,
        ]);

        $response = $this->actingAs($this->tenant['clientUser'])
            ->getJson('/api/v1/portal/notifications');

        $response->assertOk();
        $response->assertJsonPath('data.documents_awaiting_signature', 2);
        $response->assertJsonPath('data.new_matters', 1);
        $response->assertJsonPath('data.unread_message_threads', 1);
        $response->assertJsonPath('data.total', 4);
    }

    public function test_it_ignores_another_clients_activity(): void
    {
        $this->envelopeFor($this->tenant['otherClient']->id, 'sent');

        $response = $this->actingAs($this->tenant['clientUser'])
            ->getJson('/api/v1/portal/notifications');

        $response->assertOk();
        $response->assertJsonPath('data.documents_awaiting_signature', 0);
    }

    public function test_a_read_staff_message_does_not_count_as_unread(): void
    {
        $thread = MessageThread::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'client_id' => $this->tenant['client']->id,
            'matter_id' => $this->tenant['matter']->id,
            'staff_user_id' => $this->tenant['owner']->id,
        ]);
        Message::factory()->create([
            'thread_id' => $thread->id,
            'sender_id' => $this->tenant['owner']->id,
            'read_at' => now(),
        ]);

        $response = $this->actingAs($this->tenant['clientUser'])
            ->getJson('/api/v1/portal/notifications');

        $response->assertOk();
        $response->assertJsonPath('data.unread_message_threads', 0);
    }

    private function envelopeFor(int $clientId, string $status): Envelope
    {
        return Envelope::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $clientId,
            'document_id' => $this->tenant['document']->id,
            'status' => $status,
        ]);
    }
}
