<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Client\Tests;

use PactTrackSDK\SharedResources\Modules\Client\Application\Action\SearchClientsHandler;
use PactTrackSDK\SharedResources\Modules\Client\Application\DTO\ClientSearchData;
use PactTrackSDK\SharedResources\Modules\Client\Models\Client;
use PactTrackSDK\SharedResources\Modules\User\Models\Provider;
use PactTrackSDK\SharedResources\Modules\User\Models\User;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;

/**
 * Backs the "Search or select client…" field on the Upload Documents modal
 * (ClientController::search, see .claude/rules/document.md) — only a client
 * who has actually accepted their invitation (status 'active', user_id set)
 * has a portal to receive/view anything filed against them, so an invited-
 * but-not-onboarded row must never appear in this picker.
 */
class SearchClientsHandlerTest extends BaseTest
{
    private SearchClientsHandler $handler;

    private Provider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = app(SearchClientsHandler::class);
        $this->provider = Provider::factory()->create();
    }

    public function test_only_active_clients_with_a_linked_user_are_returned(): void
    {
        $active = $this->client('Alice Active', 'active', withUser: true);
        $this->client('Ivan Invited', 'invited', withUser: false);
        $this->client('Ada Active No Login', 'active', withUser: false);
        $this->client('Archie Archived', 'archived', withUser: true);

        $results = $this->handler->handle(new ClientSearchData($this->provider->id, '', 20));

        $this->assertCount(1, $results);
        $this->assertSame($active->id, $results->first()->id);
    }

    public function test_search_term_still_applies_on_top_of_the_active_filter(): void
    {
        $this->client('Alice Active', 'active', withUser: true);
        $match = $this->client('Bob Active', 'active', withUser: true);

        $results = $this->handler->handle(new ClientSearchData($this->provider->id, 'Bob', 20));

        $this->assertCount(1, $results);
        $this->assertSame($match->id, $results->first()->id);
    }

    public function test_an_invited_client_matching_the_search_term_is_still_excluded(): void
    {
        $this->client('Ivan Invited', 'invited', withUser: false);

        $results = $this->handler->handle(new ClientSearchData($this->provider->id, 'Ivan', 20));

        $this->assertCount(0, $results);
    }

    public function test_results_stay_scoped_to_the_requesting_provider(): void
    {
        $otherProvider = Provider::factory()->create();
        $this->client('Alice Active', 'active', withUser: true, provider: $otherProvider);
        $mine = $this->client('Alice Active', 'active', withUser: true);

        $results = $this->handler->handle(new ClientSearchData($this->provider->id, '', 20));

        $this->assertCount(1, $results);
        $this->assertSame($mine->id, $results->first()->id);
    }

    private function client(string $name, string $status, bool $withUser, ?Provider $provider = null): Client
    {
        $provider ??= $this->provider;

        $userId = null;

        if ($withUser) {
            $userId = User::factory()->create(['provider_id' => $provider->id])->id;
        }

        return Client::factory()->create([
            'provider_id' => $provider->id,
            'name' => $name,
            'status' => $status,
            'user_id' => $userId,
        ]);
    }
}
