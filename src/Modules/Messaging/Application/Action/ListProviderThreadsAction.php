<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Messaging\Application\Action;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use PactTrackSDK\SharedResources\Modules\Messaging\Application\DTO\ThreadListData;
use PactTrackSDK\SharedResources\Modules\Messaging\Application\Port\Repository\MessageRepository;

/**
 * Backs GET /api/v1/messages/threads — one page of the inbox for the
 * "All" or "Unread" tab on /dashboard/messages. Thin: the provider
 * scoping, eager-loading, unread annotation and ordering all live in the
 * repository (EloquentMessageRepository::inboxQuery), this only picks the
 * tab.
 *
 * The caller (MessageController) has already authorized `viewAny` on
 * MessageThread; passing provider_id through keeps the query itself
 * tenant-scoped too — a gate alone is never sufficient for an index (see
 * TenantScopedPolicy).
 *
 * @return LengthAwarePaginator<int, \PactTrackSDK\SharedResources\Modules\Messaging\Models\MessageThread>
 */
class ListProviderThreadsAction
{
    public function __construct(private readonly MessageRepository $messages)
    {
    }

    public function handle(ThreadListData $data): LengthAwarePaginator
    {
        if ($data->isUnreadOnly()) {
            return $this->messages->paginateUnreadThreadsForProvider(
                $data->provider_id,
                $data->current_user_id,
                $data->per_page,
                $data->page,
            );
        }

        return $this->messages->paginateThreadsForProvider(
            $data->provider_id,
            $data->current_user_id,
            $data->per_page,
            $data->page,
        );
    }
}
