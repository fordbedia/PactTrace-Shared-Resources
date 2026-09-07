<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Client\Tests;

use PactTrackSDK\SharedResources\Modules\Client\Application\Action\SearchClientsHandler;
use PactTrackSDK\SharedResources\Modules\Client\Application\DTO\ClientSearchData;
use PactTrackSDK\SharedResources\Modules\Client\Models\Client;
use PactTrackSDK\SharedResources\Modules\User\Models\Provider;
use PactTrackSDK\SharedResources\Modules\User\Models\User;
use PactTrackSDK\SharedResources\Modules\Workspace\Domain\Ports\CurrentWorkspace;
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

    public function test_the_result_set_is_capped_at_the_requested_limit(): void
    {
        // The New Message modal (/dashboard/messages) asks for 5; more than
        // 5 clients match the empty search, so the cap must actually apply.
        for ($i = 0; $i < 8; $i++) {
            $this->client("Client Number {$i}", 'active', withUser: true);
        }

        $results = $this->handler->handle(new ClientSearchData($this->provider->id, '', 5));

        $this->assertCount(5, $results);
    }

    /**
     * A Client is provider-scoped, never workspace-scoped (see
     * .claude/rules/client.md). Regression: `Client` briefly carried the
     * `BelongsToWorkspace` global scope plus a `clients.workspace_id` column,
     * so whenever a workspace context was active every scoped client query
     * gained `AND clients.workspace_id = <active>` and silently dropped every
     * client whose `workspace_id` was null — which was every client, since the
     * invite flow never set one. Reported live: a client showed for an admin
     * whose session had no workspace context but vanished for the owner who
     * had switched into a workspace.
     */
    public function test_results_are_not_filtered_by_the_active_workspace_context(): void
    {
        $match = $this->client('Workspace Agnostic', 'active', withUser: true);

        // Simulate a signed-in user who has switched into a workspace.
        app(CurrentWorkspace::class)->setId(4242);

        $results = $this->handler->handle(new ClientSearchData($this->provider->id, 'Workspace', 20));

        $this->assertCount(1, $results);
        $this->assertSame($match->id, $results->first()->id);
    }

    public function test_a_partial_email_match_is_returned_like_a_partial_name(): void
    {
        $byEmail = $this->client('Priya Nair', 'active', withUser: true, email: 'priya@harmon-estates.test');
        $this->client('Sam Delgado', 'active', withUser: true, email: 'sam@othertld.test');

        $results = $this->handler->handle(new ClientSearchData($this->provider->id, 'harmon-estates', 20));

        $this->assertCount(1, $results);
        $this->assertSame($byEmail->id, $results->first()->id);
    }

    private function client(
        string $name,
        string $status,
        bool $withUser,
        ?Provider $provider = null,
        ?string $email = null,
    ): Client {
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
            ...($email !== null ? ['email' => $email] : []),
        ]);
    }
}
