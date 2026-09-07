<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Matter\Tests;

use PactTrackSDK\SharedResources\Modules\Client\Models\Client;
use PactTrackSDK\SharedResources\Modules\Matter\Infrastructure\Repositories\Eloquent\EloquentMattersRepository;
use PactTrackSDK\SharedResources\Modules\Matter\Models\Matter;
use PactTrackSDK\SharedResources\Modules\Workspace\Domain\Ports\CurrentWorkspace;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;

/**
 * `searchForSelection()` backs both the "Search or select matter…" field on
 * the Upload Documents modal (/dashboard/documents) and — via
 * MatterResource's `client` relation — that same modal's own Client field,
 * which auto-fills and locks itself from the selected matter's client. See
 * .claude/rules/document.md.
 */
class EloquentMattersRepositoryTest extends BaseTest
{
    public function test_search_for_selection_eager_loads_the_matters_client(): void
    {
        $client = Client::factory()->create(['name' => 'Jane Smith', 'company_name' => 'Smith Co']);
        Matter::factory()->create(['client_id' => $client->id, 'provider_id' => $client->provider_id, 'name' => 'Estate Plan']);

        $repository = app(EloquentMattersRepository::class);

        $results = $repository->searchForSelection($client->provider_id, 'Estate', 10);

        $this->assertCount(1, $results);
        $this->assertTrue($results->first()->relationLoaded('client'));
        $this->assertSame('Jane Smith', $results->first()->client->name);
        $this->assertSame('Smith Co', $results->first()->client->company_name);
    }

    /**
     * `searchClientsForSelection()` backs the New Matter drawer's client
     * picker. A Client is provider-scoped, NOT workspace-scoped (see
     * .claude/rules/client.md) — any of a provider's clients may own a Matter
     * in any workspace.
     *
     * Regression: this method used to call `Client::whereWorkspace($id)` off
     * the `BelongsToWorkspace` trait. With the trait (and the
     * `clients.workspace_id` column) removed, that call degraded to Eloquent's
     * dynamic `where('workspace', $id)` and threw
     * `SQLSTATE[42S22] ... Unknown column 'workspace'`. Before that, while the
     * column existed, it hid every client with a null `workspace_id` whenever
     * a workspace context was active.
     */
    public function test_search_clients_for_selection_returns_clients_regardless_of_active_workspace(): void
    {
        $client = Client::factory()->create(['name' => 'Rae Nakamura']);

        // Simulate a signed-in user who has switched into a workspace.
        app(CurrentWorkspace::class)->setId(4242);

        $results = app(EloquentMattersRepository::class)
            ->searchClientsForSelection($client->provider_id, 'Rae', 5);

        $this->assertCount(1, $results);
        $this->assertSame($client->id, $results->first()->id);
    }
}
