<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Notification\Application\Action;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use PactTrackSDK\SharedResources\Modules\Notification\Application\DTO\AuditLogListData;
use PactTrackSDK\SharedResources\Modules\Notification\Application\Ports\Repository\AuditLogRepository;

/**
 * Thin — delegates straight to the repository, same shape as
 * ListMattersHandler. One filtered, paginated list is all this feature needs,
 * so there is no separate "listing service" indirection the way the Matter
 * module has (that split exists there for stat counts + several filter
 * variants; neither applies here).
 */
class ListAuditLogsHandler
{
    public function __construct(private AuditLogRepository $repository)
    {
    }

    public function handle(AuditLogListData $data): LengthAwarePaginator
    {
        return $this->repository->paginateFiltered($data);
    }
}
