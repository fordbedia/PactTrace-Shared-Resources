<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Messaging\Tests;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use PactTrackSDK\SharedResources\Modules\Messaging\Application\Action\AppendMessageToThread;
use PactTrackSDK\SharedResources\Modules\Messaging\Events\InboxUpdated;
use PactTrackSDK\SharedResources\Modules\Messaging\Events\NewMessage;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\MessageThread;
use PactTrackSDK\SharedResources\Modules\Notification\Mail\NewMessageFromClientEmail;
use PactTrackSDK\SharedResources\Modules\Notification\Support\Notification;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * Dispatch-site gating for `new_message_from_client` (default ON) — see
 * .claude/rules/notification.md, "Notification::isset() gating at dispatch
 * sites". The immediate "your client sent a message" email to the thread's
 * one staff member fires only on a client -> staff message and only when that
 * staffer has the notification on. Independent of the delayed
 * `unread_message_reminder` (SendStaffUnreadMessageReminder), which is faked
 * out here via Bus::fake().
 */
class NewMessageFromClientNotificationTest extends BaseTest
{
    private AppendMessageToThread $action;

    private TestScenarioCollection $tenant;

    private MessageThread $thread;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([NewMessage::class, InboxUpdated::class]);
        Bus::fake();
        Mail::fake();

        $this->action = app(AppendMessageToThread::class);
        $this->tenant = ProviderTenantScenario::make('new-message-notify');
        $this->thread = MessageThread::factory()
            ->forMatter($this->tenant['matter'], $this->tenant['staff'])
            ->create();
    }

    public function test_a_client_message_emails_the_thread_staffer(): void
    {
        $this->action->handle(
            $this->thread,
            (int) $this->tenant['clientUser']->id,
            'Do you need anything else from me before Friday?',
        );

        Mail::assertQueued(
            NewMessageFromClientEmail::class,
            fn (NewMessageFromClientEmail $mail): bool =>
                $mail->hasTo($this->tenant['staff']->email)
                && $mail->clientName === $this->tenant['client']->name
                && $mail->workspaceName === $this->tenant['workspace']->name
                && str_contains($mail->render(), $this->tenant['workspace']->name)
                && str_contains($mail->messagePreview, 'before Friday'),
        );
    }

    public function test_a_staff_message_does_not_email_anyone(): void
    {
        $this->action->handle(
            $this->thread,
            (int) $this->tenant['staff']->id,
            'Nothing further needed — thanks.',
        );

        Mail::assertNotQueued(NewMessageFromClientEmail::class);
    }

    public function test_no_email_when_the_staffer_disabled_new_message_from_client(): void
    {
        Notification::disable('new_message_from_client', $this->tenant['staff']);

        $this->action->handle(
            $this->thread,
            (int) $this->tenant['clientUser']->id,
            'Following up on the retainer.',
        );

        Mail::assertNotQueued(NewMessageFromClientEmail::class);
    }

    public function test_still_emails_when_only_an_unrelated_user_disabled_the_notification(): void
    {
        Notification::disable('new_message_from_client', $this->tenant['owner']);

        $this->action->handle(
            $this->thread,
            (int) $this->tenant['clientUser']->id,
            'Following up on the retainer.',
        );

        Mail::assertQueued(
            NewMessageFromClientEmail::class,
            fn (NewMessageFromClientEmail $mail): bool => $mail->hasTo($this->tenant['staff']->email),
        );
    }
}
