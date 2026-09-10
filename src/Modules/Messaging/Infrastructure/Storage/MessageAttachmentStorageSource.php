<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Messaging\Infrastructure\Storage;

use Illuminate\Support\Facades\DB;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\StorageSource;

/**
 * The `message_attachments` table's contribution to a provider's stored-bytes
 * total — the gap this whole feature exists to close (see
 * .claude/rules/messaging.md, "Attachments"). Before this, files a client
 * attached to a message occupied real S3 storage that never counted against
 * the plan quota.
 *
 * A raw query builder deliberately: `message_attachments` reaches a provider
 * only through `messages` → `message_threads`, and the total must ignore
 * every model scope —
 *  - `message_threads` is soft-deleted on archive, but an archived thread's
 *    attachment bytes are still stored, so archived threads are counted;
 *  - the count is provider-wide, never narrowed to an ambient workspace.
 *
 * `document_id IS NULL` excludes an attachment that merely points at an
 * existing Document (those bytes are already counted by DocumentStorageSource
 * — no code path builds one today, but the guard keeps the sum correct if one
 * ever does). NULL `size` rows are ignored by SUM.
 */
final class MessageAttachmentStorageSource implements StorageSource
{
    public function sumBytesForProvider(int $providerId): int
    {
        return (int) DB::table('message_attachments')
            ->join('messages', 'messages.id', '=', 'message_attachments.message_id')
            ->join('message_threads', 'message_threads.id', '=', 'messages.thread_id')
            ->where('message_threads.provider_id', $providerId)
            ->whereNull('message_attachments.document_id')
            ->sum('message_attachments.size');
    }

    public function key(): string
    {
        return 'message_attachments';
    }
}
