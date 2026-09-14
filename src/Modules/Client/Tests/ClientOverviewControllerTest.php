<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Client\Tests;

use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\SanctumServiceProvider;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\Matter\Models\Matter;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\Message;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\MessageThread;
use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\TestCase\Extras\LoadsModuleApiRoutes;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * `GET /clients/{client}/overview` — the Client Detail page's Overview tab
 * (`GetClientOverview`, `EloquentClientOverviewReader`,
 * `AuditLogRepository::recentForClient()`). See .claude/rules/client.md,
 * "Client Detail page — Overview tab".
 *
 * Registers SanctumServiceProvider and authenticates with Sanctum::actingAs()
 * — BaseTest's shared harness only configures the `web` guard, same reason
 * ClientControllerTest does this.
 */
class ClientOverviewControllerTest extends BaseTest
{
    use LoadsModuleApiRoutes;

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

        $this->tenant = ProviderTenantScenario::make('client-overview-http');

        // Baseline scenario fixtures carry a randomised status — pin them so
        // every test starts from a known, deterministic count.
        $this->tenant['matter']->update(['status' => 'active']);
        $this->tenant['document']->update(['status' => 'draft']);
        $this->tenant['envelope']->update(['status' => 'draft']);
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson("/api/v1/clients/{$this->tenant['client']->id}/overview")
            ->assertStatus(401);
    }

    /**
     * `ClientPolicy::view` extends `TenantScopedPolicy` — a client belonging
     * to a different provider fails the tenant check and denies, same as
     * every other tenant-scoped resource (see .claude/rules/user.md,
     * "Policies").
     */
    public function test_a_client_belonging_to_another_provider_is_denied(): void
    {
        $otherTenant = ProviderTenantScenario::make('client-overview-http-other');

        Sanctum::actingAs($this->tenant['owner']);

        $this->getJson("/api/v1/clients/{$otherTenant['client']->id}/overview")
            ->assertStatus(403);
    }

    public function test_staff_can_view_a_clients_overview(): void
    {
        Sanctum::actingAs($this->tenant['staff']);

        $this->getJson("/api/v1/clients/{$this->tenant['client']->id}/overview")
            ->assertOk();
    }

    public function test_it_reports_matter_document_and_envelope_figures_for_the_client(): void
    {
        $client = $this->tenant['client'];
        $provider = $this->tenant['provider'];
        $workspace = $this->tenant['workspace'];

        // Baseline matter is 'active'; add one more active + two closed.
        Matter::factory()->create([
            'provider_id' => $provider->id,
            'workspace_id' => $workspace->id,
            'client_id' => $client->id,
            'status' => 'on_hold',
        ]);
        Matter::factory()->create([
            'provider_id' => $provider->id,
            'workspace_id' => $workspace->id,
            'client_id' => $client->id,
            'status' => 'completed',
        ]);
        Matter::factory()->create([
            'provider_id' => $provider->id,
            'workspace_id' => $workspace->id,
            'client_id' => $client->id,
            'status' => 'cancelled',
        ]);

        // Baseline document is 'draft'; add one 'sent' + one 'partially_signed'
        // (both out for signature), plus one archived document that must be
        // excluded from every document figure entirely.
        Document::factory()->create([
            'provider_id' => $provider->id,
            'workspace_id' => $workspace->id,
            'client_id' => $client->id,
            'matter_id' => $this->tenant['matter']->id,
            'uploaded_by' => $this->tenant['owner']->id,
            'status' => 'sent',
        ]);
        Document::factory()->create([
            'provider_id' => $provider->id,
            'workspace_id' => $workspace->id,
            'client_id' => $client->id,
            'matter_id' => $this->tenant['matter']->id,
            'uploaded_by' => $this->tenant['owner']->id,
            'status' => 'partially_signed',
        ]);
        Document::factory()->create([
            'provider_id' => $provider->id,
            'workspace_id' => $workspace->id,
            'client_id' => $client->id,
            'matter_id' => $this->tenant['matter']->id,
            'uploaded_by' => $this->tenant['owner']->id,
            'status' => 'sent',
            'archived_at' => now(),
        ]);

        // Baseline envelope is 'draft'; add one 'sent' (awaiting) and one
        // 'completed' (not awaiting).
        Envelope::factory()->create([
            'provider_id' => $provider->id,
            'workspace_id' => $workspace->id,
            'client_id' => $client->id,
            'document_id' => $this->tenant['document']->id,
            'status' => 'sent',
        ]);
        Envelope::factory()->create([
            'provider_id' => $provider->id,
            'workspace_id' => $workspace->id,
            'client_id' => $client->id,
            'document_id' => $this->tenant['document']->id,
            'status' => 'completed',
        ]);

        Sanctum::actingAs($this->tenant['owner']);

        $response = $this->getJson("/api/v1/clients/{$client->id}/overview");

        $response->assertOk();
        $response->assertJsonPath('data.matters.active', 2);
        $response->assertJsonPath('data.matters.closed', 2);
        $response->assertJsonPath('data.matters.total', 4);
        $response->assertJsonPath('data.documents.total', 3); // baseline + sent + partially_signed; archived excluded
        $response->assertJsonPath('data.documents.out_for_signature', 2);
        $response->assertJsonPath('data.envelopes.total', 3);
        $response->assertJsonPath('data.envelopes.awaiting_signature', 1);
    }

    /**
     * `unreadMessageThreadCountForStaff` is relative to whichever staff
     * member is viewing (`auth()->id()`), not a client-wide figure — a
     * message the client sent is "unread" for every staffer who hasn't
     * opened it yet, and "read" the moment one of them does.
     */
    public function test_message_figures_are_scoped_to_the_viewing_staff_member(): void
    {
        $client = $this->tenant['client'];

        $unreadThread = MessageThread::factory()->forMatter($this->tenant['matter'], $this->tenant['owner'])->create();
        Message::factory()->create([
            'thread_id' => $unreadThread->id,
            'sender_id' => $this->tenant['clientUser']->id,
            'read_at' => null,
        ]);

        $readThread = MessageThread::factory()->forMatter($this->tenant['matter'], $this->tenant['owner'])->create();
        Message::factory()->create([
            'thread_id' => $readThread->id,
            'sender_id' => $this->tenant['clientUser']->id,
            'read_at' => now(),
        ]);

        Sanctum::actingAs($this->tenant['owner']);

        $response = $this->getJson("/api/v1/clients/{$client->id}/overview");

        $response->assertOk();
        $response->assertJsonPath('data.messages.total', 2);
        $response->assertJsonPath('data.messages.unread', 1);
    }

    public function test_the_open_matters_panel_lists_the_clients_recent_matters_with_document_and_envelope_counts(): void
    {
        $client = $this->tenant['client'];

        Sanctum::actingAs($this->tenant['owner']);

        $response = $this->getJson("/api/v1/clients/{$client->id}/overview");

        $response->assertOk();
        $openMatters = $response->json('data.open_matters');

        $this->assertCount(1, $openMatters);
        $this->assertSame($this->tenant['matter']->id, $openMatters[0]['id']);
        $this->assertSame($this->tenant['matter']->public_id, $openMatters[0]['public_id']);
        $this->assertSame(1, $openMatters[0]['documents_count']);
        $this->assertSame(1, $openMatters[0]['envelopes_count']);
    }

    /**
     * `audit_logs` has no `client_id` column — `recentForClient()` matches
     * via the polymorphic `auditable_type`/`auditable_id` pair against this
     * client's own Matter/Document/Envelope/MessageThread rows (see
     * .claude/rules/client.md). A row belonging to another client, or to no
     * auditable at all, must never appear here.
     */
    public function test_the_recent_activity_feed_is_scoped_to_this_clients_own_records(): void
    {
        $client = $this->tenant['client'];
        $provider = $this->tenant['provider'];

        AuditLog::factory()->forProvider($provider)->byUser($this->tenant['owner'])->create([
            'action' => 'matter.created',
            'auditable_type' => Matter::class,
            'auditable_id' => $this->tenant['matter']->id,
        ]);

        // Another client's own matter — must not leak into this client's feed.
        AuditLog::factory()->forProvider($provider)->byUser($this->tenant['owner'])->create([
            'action' => 'matter.created',
            'auditable_type' => Matter::class,
            'auditable_id' => $this->tenant['otherMatter']->id,
        ]);

        // A system-wide row with no auditable at all.
        AuditLog::factory()->system()->forProvider($provider)->create([
            'action' => 'subscription.trial_expired',
        ]);

        Sanctum::actingAs($this->tenant['owner']);

        $response = $this->getJson("/api/v1/clients/{$client->id}/overview");

        $response->assertOk();
        $activity = $response->json('data.recent_activity');

        $this->assertCount(1, $activity);
        $this->assertSame('matter.created', $activity[0]['action']);
        $this->assertSame('Matter', $activity[0]['auditable_type']);
    }
}
