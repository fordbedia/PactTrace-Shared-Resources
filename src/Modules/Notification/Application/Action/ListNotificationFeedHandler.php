<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Notification\Application\Action;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use PactTrackSDK\SharedResources\Modules\Notification\Application\Ports\Repository\AuditLogRepository;

/**
 * The notification bell's feed (AppShell's Topbar) — see
 * .claude/rules/notification.md. Thin: delegates straight to
 * `AuditLogRepository::paginateUnreadForUser()`, which already carries the
 * tenant + current-workspace scoping ("scoped to the provider and
 * workspace") and the "already read/cleared rows don't come back" rule.
 */
class ListNotificationFeedHandler
{
    public function __construct(private AuditLogRepository $repository)
    {
    }

    public function handle(int $providerId, int $userId, int $perPage, ?int $page): LengthAwarePaginator
    {
        return $this->repository->paginateUnreadForUser($providerId, $userId, $perPage, $page);
    }
}
