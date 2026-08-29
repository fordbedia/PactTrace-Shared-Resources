<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Messaging\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\Message;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\MessageThread;
use PactTrackSDK\SharedResources\Modules\Notification\Mail\StaffUnreadMessageReminderEmail;
use Throwable;

/**
 * Emails a thread's one staff member when a message their client sent has sat
 * unread for 5 minutes. Dispatched (delayed) from AppendMessageToThread on every
 * client -> staff message; re-checks the live state at run time and sends only if
 * the message is still unread AND no reminder has already gone out this episode.
 *
 * "One reminder per unread episode, not a repeating nag" — confirmed with Ed.
 * The episode is tracked by `message_threads.staff_reminder_sent_at`: set here on
 * send, cleared by MessageThread::markReadFor() the moment the staffer reads. So a
 * burst of three client messages before the first reminder fires still produces a
 * single email, and a fresh client message after the staffer has caught up can
 * trigger a new one. See .claude/rules/messaging.md, "Unread-message reminder
 * email (staff)".
 *
 * Constructor takes scalars only (serialisation-safe), matching the Mailable/DTO
 * convention used across the Notification module.
 */
class SendStaffUnreadMessageReminder implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Characters of the message body shown as a preview in the email. */
    private const PREVIEW_LENGTH = 140;

    public function __construct(
        public readonly int $threadId,
        public readonly int $messageId,
    ) {
    }

    public function handle(): void
    {
        /** @var MessageThread|null $thread */
        $thread = MessageThread::query()
            ->with(['staffMember', 'client', 'matter'])
            ->find($this->threadId);

        // A thread archived (soft-deleted) in the 5-minute window won't resolve
        // here — no reminder for an archived conversation, which is correct.
        if ($thread === null) {
            return;
        }

        /** @var Message|null $message */
        $message = Message::query()->find($this->messageId);

        if ($message === null) {
            return;
        }

        // The staffer already opened the thread — MarkThreadReadAction stamped
        // `read_at` on this message. Nothing to nudge.
        if ($message->read_at !== null) {
            return;
        }

        // A reminder for this unread episode already went out. markReadFor()
        // clears this back to null once the staffer reads, so this only skips
        // the 2nd/3rd/... message of the same still-unread burst.
        if ($thread->staff_reminder_sent_at !== null) {
            return;
        }

        $staffUserId = (int) $thread->staff_user_id;

        // Defensive: the triggering message could have been individually
        // cleared even though `read_at` above wasn't (it shouldn't happen, but
        // the send below is worthless if the thread has nothing unread).
        if (! $thread->hasUnreadFor($staffUserId)) {
            return;
        }

        $recipient = $thread->staffMember?->email;

        if (empty($recipient)) {
            return;
        }

        try {
            Mail::to($recipient)->queue(new StaffUnreadMessageReminderEmail(
                staffName: (string) ($thread->staffMember?->name ?? 'there'),
                clientName: (string) ($thread->client?->name ?? 'Your client'),
                matterName: (string) ($thread->matter?->name ?? ''),
                threadSubject: (string) $thread->subject,
                messagePreview: Str::limit((string) $message->body, self::PREVIEW_LENGTH),
                ctaUrl: rtrim((string) config('app.frontend_url'), '/') . '/dashboard/messages',
            ));

            $thread->forceFill(['staff_reminder_sent_at' => now()])->save();
        } catch (Throwable $e) {
            // Best-effort, same contract as RecordSignatureCompletionUseCase::notifyClient():
            // a mail transport failure must not fail the job loudly / retry forever.
            // `staff_reminder_sent_at` is left null so a later message can retry.
            Log::warning('SendStaffUnreadMessageReminder: reminder email failed to queue', [
                'thread_id' => $this->threadId,
                'message_id' => $this->messageId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
