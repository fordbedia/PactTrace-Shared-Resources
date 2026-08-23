<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Matter\Tests;

use PactTrackSDK\SharedResources\Modules\Client\Models\Client;
use PactTrackSDK\SharedResources\Modules\Matter\Infrastructure\Repositories\Eloquent\EloquentMattersRepository;
use PactTrackSDK\SharedResources\Modules\Matter\Models\Matter;
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
}
