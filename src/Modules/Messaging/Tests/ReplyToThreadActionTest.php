<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Messaging\Tests;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use PactTrackSDK\SharedResources\Modules\Messaging\Application\Action\ReplyToThreadAction;
use PactTrackSDK\SharedResources\Modules\Messaging\Application\DTO\ReplyMessageData;
use PactTrackSDK\SharedResources\Modules\Messaging\Events\InboxUpdated;
use PactTrackSDK\SharedResources\Modules\Messaging\Events\NewMessage;
use PactTrackSDK\SharedResources\Modules\Messaging\Jobs\SendStaffUnreadMessageReminder;
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

        // InboxUpdated must carry the thread's client_id so it also fans out
        // on the portal-side `messages.client.{clientId}` channel — without
        // that the client portal gets no live nudge for a staff -> client
        // message (see .claude/rules/messaging.md, "Real-time transport").
        Event::assertDispatched(InboxUpdated::class, function (InboxUpdated $event) use ($thread): bool {
            return $event->providerId === (int) $thread->provider_id
                && $event->clientId === (int) $thread->client_id
                && in_array(
                    'private-messages.client.' . $thread->client_id,
                    array_map(static fn ($c) => (string) $c, $event->broadcastOn()),
                    true,
                );
        });
    }

    public function test_a_client_reply_schedules_the_delayed_staff_reminder(): void
    {
        Bus::fake();

        $thread = MessageThread::factory()
            ->forMatter($this->tenant['matter'], $this->tenant['owner'])
            ->create();

        $this->action->handle(
            $thread,
            new ReplyMessageData(sender_id: $this->tenant['clientUser']->id, body: 'any updates?'),
        );

        Bus::assertDispatched(
            SendStaffUnreadMessageReminder::class,
            fn (SendStaffUnreadMessageReminder $job): bool =>
                $job->threadId === $thread->id && $job->delay !== null,
        );
    }

    public function test_a_staff_reply_never_schedules_a_reminder(): void
    {
        Bus::fake();

        $thread = MessageThread::factory()
            ->forMatter($this->tenant['matter'], $this->tenant['owner'])
            ->create();

        // sender IS the thread's staff_user_id — a staff -> client message.
        $this->action->handle(
            $thread,
            new ReplyMessageData(sender_id: $this->tenant['owner']->id, body: 'here you go'),
        );

        Bus::assertNotDispatched(SendStaffUnreadMessageReminder::class);
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
