<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Matter\Application\Services;

use Illuminate\Support\Facades\Mail;
use PactTrackSDK\SharedResources\Modules\Matter\Models\Matter;
use PactTrackSDK\SharedResources\Modules\Notification\Mail\MilestoneUpdatedEmail;
use PactTrackSDK\SharedResources\Modules\Notification\Support\Notification;
use Throwable;

/**
 * The single "build + send the milestone-updated email" step, so the two
 * trigger sites — automatic milestone completion
 * (MilestoneProgressionService::completeMilestone) and a manual matter-status
 * edit (UpdateMattersHandler) — don't each duplicate recipient resolution and
 * Mailable construction. See .claude/rules/notification.md,
 * "Notification::isset() gating at dispatch sites".
 *
 * Recipient is the matter's assigned staff member (or the provider owner),
 * resolved once by MatterNotificationRecipientResolver. The send is gated on
 * that recipient's `milestone_updated` preference (default OFF) and is
 * best-effort — a mail failure is reported and swallowed, never allowed to
 * break the transition that triggered it, same contract as
 * RecordSignatureCompletionUseCase::notifyClient().
 */
final class MilestoneNotifier
{
    public function __construct(
        private readonly MatterNotificationRecipientResolver $recipients,
    ) {
    }

    public function milestoneCompleted(Matter $matter, string $milestoneName): void
    {
        $this->send(
            $matter,
            headline: 'Milestone completed',
            detail: "the \"{$milestoneName}\" milestone is now complete.",
        );
    }

    public function matterStatusChanged(Matter $matter, string $previousStatus): void
    {
        $this->send(
            $matter,
            headline: 'Matter status updated',
            detail: "the matter status changed from \"{$previousStatus}\" to \"{$matter->status}\".",
        );
    }

    private function send(Matter $matter, string $headline, string $detail): void
    {
        try {
            $recipient = $this->recipients->forMatter($matter);

            if ($recipient === null || ($recipient->email ?? '') === '') {
                return;
            }

            if (! Notification::isset('milestone_updated', $recipient)) {
                return;
            }

            Mail::to($recipient->email)->queue(new MilestoneUpdatedEmail(
                recipientName: (string) ($recipient->name ?? 'there'),
                matterName: (string) $matter->name,
                workspaceName: (string) ($matter->workspace?->name ?? ''),
                headline: $headline,
                detail: $detail,
                ctaUrl: rtrim((string) config('app.frontend_url'), '/') . '/dashboard/matters/' . $matter->public_id,
            ));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
