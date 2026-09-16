<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Notification\Application\UseCases;

use PactTrackSDK\SharedResources\Modules\Notification\Application\Ports\Repository\NotificationReadRepository;

/**
 * The bell's "Clear" button — marks every notification currently visible to
 * this user as read, so the next fetch returns an empty feed. See
 * .claude/rules/notification.md.
 */
class ClearNotifications
{
    public function __construct(private NotificationReadRepository $repository)
    {
    }

    public function handle(int $userId, int $providerId): void
    {
        $this->repository->markAllRead($userId, $providerId);
    }
}
