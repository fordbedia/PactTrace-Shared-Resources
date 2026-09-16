<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Notification\Application\UseCases;

use PactTrackSDK\SharedResources\Modules\Notification\Application\Ports\Repository\NotificationReadRepository;

/**
 * "The user opened/clicked this one notification" — see
 * .claude/rules/notification.md. Idempotent: reading an already-read row
 * changes nothing.
 */
class MarkNotificationRead
{
    public function __construct(private NotificationReadRepository $repository)
    {
    }

    public function handle(int $userId, int $auditLogId): void
    {
        $this->repository->markRead($userId, $auditLogId);
    }
}
