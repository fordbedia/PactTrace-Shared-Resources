<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Messaging\Tests;

use PactTrackSDK\SharedResources\Modules\Messaging\Application\Action\ListMatterMessagesAction;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\MessageThread;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * The list-a-matter's-messages use case. Thin over the repository, so the
 * two things worth asserting are that it stays provider-scoped and that an
 * empty matter is an empty collection, not an error.
 */
class ListMatterMessagesActionTest extends BaseTest
{
    private ListMatterMessagesAction $action;

    private TestScenarioCollection $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = app(ListMatterMessagesAction::class);
        $this->tenant = ProviderTenantScenario::make('list-matter-msg');
    }

    public function test_a_matter_with_no_messages_returns_an_empty_collection(): void
    {
        $result = $this->action->handle(
            $this->tenant['provider']->id,
            $this->tenant['matter']->id,
        );

        $this->assertTrue($result->isEmpty());
    }

    public function test_it_returns_only_the_given_matters_messages_for_the_given_provider(): void
    {
        $thread = MessageThread::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'client_id' => $this->tenant['client']->id,
            'matter_id' => $this->tenant['matter']->id,
        ]);
        $thread->messages()->create(['sender_id' => $this->tenant['owner']->id, 'body' => 'mine']);

        // Same provider, different matter — must not appear.
        $otherThread = MessageThread::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'client_id' => $this->tenant['otherClient']->id,
            'matter_id' => $this->tenant['otherMatter']->id,
        ]);
        $otherThread->messages()->create(['sender_id' => $this->tenant['owner']->id, 'body' => 'other matter']);

        $result = $this->action->handle(
            $this->tenant['provider']->id,
            $this->tenant['matter']->id,
        );

        $this->assertSame(['mine'], $result->pluck('body')->all());
    }
}
