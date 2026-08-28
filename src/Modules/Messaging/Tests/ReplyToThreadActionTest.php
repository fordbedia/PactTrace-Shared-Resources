<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Messaging\Tests;

use Illuminate\Support\Facades\Event;
use PactTrackSDK\SharedResources\Modules\Messaging\Application\Action\ReplyToThreadAction;
use PactTrackSDK\SharedResources\Modules\Messaging\Application\DTO\ReplyMessageData;
use PactTrackSDK\SharedResources\Modules\Messaging\Events\InboxUpdated;
use PactTrackSDK\SharedResources\Modules\Messaging\Events\NewMessage;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\MessageThread;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;
use RuntimeException;

class ReplyToThreadActionTest extends BaseTest
{
    private ReplyToThreadAction $action;

    private TestScenarioCollection $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([NewMessage::class, InboxUpdated::class]);

        $this->action = app(ReplyToThreadAction::class);
        $this->tenant = ProviderTenantScenario::make('reply-action');
    }

    public function test_it_appends_a_message_and_bumps_activity(): void
    {
        $thread = MessageThread::factory()
            ->forMatter($this->tenant['matter'], $this->tenant['owner'])
            ->create(['last_message_at' => now()->subWeek()]);

        $message = $this->action->handle(
            $thread,
            new ReplyMessageData(sender_id: $this->tenant['owner']->id, body: 'following up'),
        );

        $this->assertSame($thread->id, $message->thread_id);
        $this->assertTrue($thread->refresh()->last_message_at->greaterThan(now()->subMinute()));
        Event::assertDispatched(NewMessage::class);
        Event::assertDispatched(InboxUpdated::class);
    }

    public function test_it_refuses_an_archived_thread(): void
    {
        $thread = MessageThread::factory()
            ->forMatter($this->tenant['matter'], $this->tenant['owner'])
            ->create();
        $thread->archive();

        $this->expectException(RuntimeException::class);

        $this->action->handle(
            $thread,
            new ReplyMessageData(sender_id: $this->tenant['owner']->id, body: 'too late'),
        );
    }
}
