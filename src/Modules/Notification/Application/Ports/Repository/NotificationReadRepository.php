<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Notification\Application\Ports\Repository;

/**
 * Read/write access to `notification_reads` — see
 * .claude/rules/notification.md and the migration's docblock. This is
 * deliberately the only place that table is touched; nothing else in the
 * app writes to it.
 */
interface NotificationReadRepository
{
    /**
     * Marks one audit log row read for this user. Idempotent — reading an
     * already-read row is a no-op, not a second row or an error.
     */
    public function markRead(int $userId, int $auditLogId): void;

    /**
     * Marks every audit log row currently visible to this user (same
     * provider/workspace scoping the feed itself uses) as read — the
     * "Clear" button. One INSERT ... SELECT, not one query per row.
     */
    public function markAllRead(int $userId, int $providerId): void;
}
