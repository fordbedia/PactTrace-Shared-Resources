<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Dashboard\Tests;

use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\SanctumServiceProvider;
use PactTrackSDK\SharedResources\Modules\Client\Models\Client;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\User\Models\Provider;
use PactTrackSDK\SharedResources\Modules\User\Models\User;
use PactTrackSDK\SharedResources\TestCase\Extras\LoadsModuleApiRoutes;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;

/**
 * HTTP coverage for `GET /api/v1/dashboard/recent-documents` — the "Recent
 * Documents" list and its All / Pending / Signed / Draft filter pills.
 *
 * The pill -> real DocumentStatus folding is asserted here: "Pending" must
 * return only `sent` + `partially_signed`, "Signed" only `completed`,
 * "Draft" only `draft`.
 */
class RecentDocumentsEndpointTest extends BaseTest
{
    use BuildsDashboardTenant;
    use LoadsModuleApiRoutes;

    private Provider $provider;
    private User $owner;

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

        [$this->provider, $this->owner] = $this->makeProviderTenant('recent-docs');
        $this->seedDocuments();
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/dashboard/recent-documents')->assertStatus(401);
    }

    public function test_forbids_client_role_users(): void
    {
        Sanctum::actingAs($this->makeClientUser($this->provider, 'recent-docs'));

        $this->getJson('/api/v1/dashboard/recent-documents')->assertStatus(403);
    }

    public function test_all_filter_returns_only_this_providers_non_archived_documents(): void
    {
        Sanctum::actingAs($this->owner);

        $rows = $this->fetchRows('/api/v1/dashboard/recent-documents', 'all');

        // 2 sent + 1 partially_signed + 1 completed + 2 draft = 6 eligible, capped at 5.
        $this->assertCount(5, $rows);

        $names = array_column($rows, 'name');
        $this->assertNotContains('archived-sent', $names); // archived excluded

        // Every row is one of this provider's own known documents — no leak.
        $mine = ['sent-a', 'sent-b', 'partial-a', 'completed-a', 'draft-a', 'draft-b'];
        foreach ($names as $name) {
            $this->assertContains($name, $mine, 'leaked another provider\'s document');
        }
    }

    public function test_pending_filter_returns_only_sent_and_partially_signed(): void
    {
        Sanctum::actingAs($this->owner);

        $rows = $this->fetchRows('/api/v1/dashboard/recent-documents', 'pending');

        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertContains($row['status'], ['sent', 'partially_signed']);
        }
    }

    public function test_signed_filter_returns_only_completed(): void
    {
        Sanctum::actingAs($this->owner);

        $rows = $this->fetchRows('/api/v1/dashboard/recent-documents', 'signed');

        $this->assertCount(1, $rows);
        $this->assertSame('completed', $rows[0]['status']);
    }

    public function test_draft_filter_returns_only_draft(): void
    {
        Sanctum::actingAs($this->owner);

        $rows = $this->fetchRows('/api/v1/dashboard/recent-documents', 'draft');

        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame('draft', $row['status']);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchRows(string $uri, string $filter): array
    {
        return $this->getJson($uri . '?filter=' . $filter)->assertOk()->json('data');
    }

    private function seedDocuments(): void
    {
        $client = Client::factory()->create(['provider_id' => $this->provider->id]);

        $make = fn (string $status, string $name, bool $archived = false) => Document::factory()->create([
            'provider_id' => $this->provider->id,
            'client_id' => $client->id,
            'uploaded_by' => $this->owner->id,
            'name' => $name,
            'status' => $status,
            'archived_at' => $archived ? now() : null,
        ]);

        $make('sent', 'sent-a');
        $make('sent', 'sent-b');
        $make('partially_signed', 'partial-a');
        $make('completed', 'completed-a');
        $make('draft', 'draft-a');
        $make('draft', 'draft-b');
        $make('sent', 'archived-sent', archived: true); // excluded from every view

        // Another provider's documents must never appear.
        [$other, $otherOwner] = $this->makeProviderTenant('recent-docs-other');
        $otherClient = Client::factory()->create(['provider_id' => $other->id]);
        Document::factory()->count(3)->create([
            'provider_id' => $other->id,
            'client_id' => $otherClient->id,
            'uploaded_by' => $otherOwner->id,
            'status' => 'sent',
            'archived_at' => null,
        ]);
    }
}
