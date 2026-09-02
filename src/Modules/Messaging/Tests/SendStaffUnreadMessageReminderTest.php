<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Messaging\Tests;

use Illuminate\Support\Facades\Mail;
use PactTrackSDK\SharedResources\Modules\Messaging\Jobs\SendStaffUnreadMessageReminder;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\Message;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\MessageThread;
use PactTrackSDK\SharedResources\Modules\Notification\Mail\StaffUnreadMessageReminderEmail;
use PactTrackSDK\SharedResources\Modules\Notification\Support\Notification;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * The delayed "your client's message is still unread" reminder to a thread's
 * one staff member — see .claude/rules/messaging.md, "Unread-message reminder
 * email (staff)". Covers: sends only when still unread and no reminder went out
 * this episode; the one-per-episode dedup via
 * `message_threads.staff_reminder_sent_at`; and that the staffer reading the
 * thread (markReadFor) resets the episode so a later message can nudge again.
 */
class SendStaffUnreadMessageReminderTest extends BaseTest
{
    private TestScenarioCollection $tenant;

    private MessageThread $thread;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->tenant = ProviderTenantScenario::make('unread-reminder');
        $this->thread = MessageThread::factory()
            ->forMatter($this->tenant['matter'], $this->tenant['staff'])
            ->create(['staff_reminder_sent_at' => null]);
    }

    private function clientMessage(): Message
    {
        return $this->thread->messages()->create([
            'sender_id' => $this->tenant['clientUser']->id,
            'body' => 'Here are the documents you asked for — let me know what else you need.',
        ]);
    }

    private function runJob(Message $message): void
    {
        (new SendStaffUnreadMessageReminder($this->thread->id, $message->id))->handle();
    }

    public function test_it_emails_the_staffer_when_the_message_is_still_unread(): void
    {
        $message = $this->clientMessage();

        $this->runJob($message);

        Mail::assertQueued(
            StaffUnreadMessageReminderEmail::class,
            fn (StaffUnreadMessageReminderEmail $mail): bool =>
                $mail->hasTo($this->tenant['staff']->email)
                && $mail->clientName === $this->tenant['client']->name,
        );

        $this->assertNotNull($this->thread->refresh()->staff_reminder_sent_at);
    }

    public function test_it_does_not_email_when_the_staffer_already_read_the_message(): void
    {
        $message = $this->clientMessage();
        $message->forceFill(['read_at' => now()])->save();

        $this->runJob($message);

        Mail::assertNothingQueued();
        $this->assertNull($this->thread->refresh()->staff_reminder_sent_at);
    }

    public function test_a_burst_of_client_messages_produces_only_one_reminder(): void
    {
        $first = $this->clientMessage();
        $second = $this->clientMessage();
        $third = $this->clientMessage();

        // The first message's job fires and sends.
        $this->runJob($first);
        // The 2nd and 3rd messages' jobs land after — same still-unread episode,
        // `staff_reminder_sent_at` is already set, so they must not send again.
        $this->runJob($second);
        $this->runJob($third);

        Mail::assertQueuedCount(1);
    }

    public function test_after_the_staffer_reads_the_thread_a_new_message_can_remind_again(): void
    {
        $first = $this->clientMessage();
        $this->runJob($first);
        Mail::assertQueuedCount(1);

        // Staffer opens the thread: every message read, and the episode resets.
        $this->thread->markReadFor((int) $this->tenant['staff']->id);
        $this->assertNull($this->thread->refresh()->staff_reminder_sent_at);

        $next = $this->clientMessage();
        $this->runJob($next);

        Mail::assertQueuedCount(2);
    }

    public function test_it_no_ops_when_the_staffer_has_no_email(): void
    {
        $this->tenant['staff']->forceFill(['email' => ''])->save();
        $message = $this->clientMessage();

        $this->runJob($message);

        Mail::assertNothingQueued();
        $this->assertNull($this->thread->refresh()->staff_reminder_sent_at);
    }

    /**
     * Dispatch-site gating (see .claude/rules/notification.md): a staffer who
     * turned "unread message reminder" off gets no email, and — like the
     * no-email and archived cases — `staff_reminder_sent_at` is left null so
     * turning it back on lets a later message nudge again.
     */
    public function test_it_no_ops_when_the_staffer_disabled_the_unread_message_reminder(): void
    {
        Notification::disable('unread_message_reminder', $this->tenant['staff']);

        $message = $this->clientMessage();

        $this->runJob($message);

        Mail::assertNothingQueued();
        $this->assertNull($this->thread->refresh()->staff_reminder_sent_at);
    }

    /**
     * The gate reads the *thread's own staffer's* preference: another
     * provider-side user disabling it must not silence this staffer's
     * reminder.
     */
    public function test_it_still_emails_when_only_an_unrelated_user_disabled_the_reminder(): void
    {
        Notification::disable('unread_message_reminder', $this->tenant['owner']);

        $message = $this->clientMessage();

        $this->runJob($message);

        Mail::assertQueued(StaffUnreadMessageReminderEmail::class);
    }

    public function test_it_no_ops_when_the_thread_was_archived(): void
    {
        $message = $this->clientMessage();
        $this->thread->archive();

        $this->runJob($message);

        Mail::assertNothingQueued();
    }

    public function test_the_email_uses_pacttrack_branding_not_the_providers(): void
    {
        $html = (new StaffUnreadMessageReminderEmail(
            staffName: 'Sam Staff',
            clientName: 'Dana Client',
            matterName: 'Acme v. Roe',
            threadSubject: 'Retainer questions',
            messagePreview: 'Quick question about the retainer…',
            ctaUrl: 'https://portal.test/dashboard/messages',
        ))->render();

        $this->assertStringContainsString('PactTrack', $html);
        $this->assertStringContainsString('Dana Client', $html);
        $this->assertStringContainsString('Acme v. Roe', $html);
        $this->assertStringContainsString('https://portal.test/dashboard/messages', $html);
        $this->assertStringNotContainsString(
            (string) ($this->tenant['provider']->business_name ?? '___no_such_value___'),
            $html,
        );
    }
}
