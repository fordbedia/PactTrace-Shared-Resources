<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Messaging\Application\DTO;

use Illuminate\Http\UploadedFile;

/**
 * The input to SendMessageAction — the first message of a NEW conversation,
 * from the staff New Message modal (/dashboard/messages) or the portal
 * staff-contact directory. Replies into an existing thread use
 * ReplyMessageData instead.
 *
 * Every scoping value is resolved by the controller BEFORE this is built,
 * never trusted from request input:
 *
 *  - `matter_id` is required (every thread belongs to one matter);
 *  - `client_id` is the matter's own `client_id` — a Matter belongsTo
 *    exactly one Client (.claude/rules/matter.md);
 *  - `staff_user_id` is the authenticated user on the staff side, or the
 *    directory-selected staffer (validated to belong to the provider) on
 *    the portal side;
 *  - `subject` is required and is what distinguishes two threads on the
 *    same matter with the same staffer.
 *
 * @param list<UploadedFile> $attachments
 */
final readonly class SendMessageData
{
    /**
     * @param list<UploadedFile> $attachments
     */
    public function __construct(
        public int $provider_id,
        public int $sender_id,
        public int $staff_user_id,
        public int $client_id,
        public int $matter_id,
        public string $subject,
        public string $body,
        public array $attachments = [],
    ) {
    }
}
