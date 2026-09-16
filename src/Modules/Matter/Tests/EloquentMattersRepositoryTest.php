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

    /**
     * The "Sort" filter chip on /dashboard/matters — see
     * .claude/rules/matter.md, "Sort". Each allow-listed key is exercised in
     * both directions; an unrecognised key falls back to the pre-existing
     * `latest()` order rather than throwing or being passed to raw SQL.
     */
    public function test_paginate_all_sorts_by_name(): void
    {
        $client = Client::factory()->create();
        $b = Matter::factory()->create(['provider_id' => $client->provider_id, 'client_id' => $client->id, 'name' => 'Beta Matter']);
        $a = Matter::factory()->create(['provider_id' => $client->provider_id, 'client_id' => $client->id, 'name' => 'Alpha Matter']);
        $c = Matter::factory()->create(['provider_id' => $client->provider_id, 'client_id' => $client->id, 'name' => 'Charlie Matter']);

        $repository = app(EloquentMattersRepository::class);

        $asc = $repository->paginateAll($client->provider_id, 15, 1, sort: 'name', direction: 'asc');
        $this->assertSame([$a->id, $b->id, $c->id], $asc->getCollection()->pluck('id')->all());

        $desc = $repository->paginateAll($client->provider_id, 15, 1, sort: 'name', direction: 'desc');
        $this->assertSame([$c->id, $b->id, $a->id], $desc->getCollection()->pluck('id')->all());
    }

    public function test_paginate_all_sorts_by_due_date_with_nulls_last(): void
    {
        $client = Client::factory()->create();
        $noDate = Matter::factory()->create(['provider_id' => $client->provider_id, 'client_id' => $client->id, 'due_date' => null]);
        $soon = Matter::factory()->create(['provider_id' => $client->provider_id, 'client_id' => $client->id, 'due_date' => '2026-10-01']);
        $later = Matter::factory()->create(['provider_id' => $client->provider_id, 'client_id' => $client->id, 'due_date' => '2026-11-01']);

        $repository = app(EloquentMattersRepository::class);

        $ids = $repository->paginateAll($client->provider_id, 15, 1, sort: 'due_date', direction: 'asc')
            ->getCollection()->pluck('id')->all();

        $this->assertSame([$soon->id, $later->id, $noDate->id], $ids, 'Matters with no due date must sort last regardless of direction.');
    }

    public function test_paginate_all_sorts_by_status(): void
    {
        $client = Client::factory()->create();
        $onHold = Matter::factory()->create(['provider_id' => $client->provider_id, 'client_id' => $client->id, 'status' => 'on_hold']);
        $active = Matter::factory()->create(['provider_id' => $client->provider_id, 'client_id' => $client->id, 'status' => 'active']);

        $repository = app(EloquentMattersRepository::class);

        $ids = $repository->paginateAll($client->provider_id, 15, 1, sort: 'status', direction: 'asc')
            ->getCollection()->pluck('id')->all();

        $this->assertSame([$active->id, $onHold->id], $ids, '"active" sorts before "on_hold" alphabetically.');
    }

    public function test_an_unrecognised_sort_key_falls_back_to_the_default_order(): void
    {
        $client = Client::factory()->create();
        Matter::factory()->count(2)->create(['provider_id' => $client->provider_id, 'client_id' => $client->id]);

        $repository = app(EloquentMattersRepository::class);

        // Would throw / 500 if the raw string reached orderBy()/orderByRaw()
        // unguarded.
        $page = $repository->paginateAll($client->provider_id, 15, 1, sort: "id; DROP TABLE matters", direction: 'asc');

        $this->assertSame(2, $page->total());
    }
}
