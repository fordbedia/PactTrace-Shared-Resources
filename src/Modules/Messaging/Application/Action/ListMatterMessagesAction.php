<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Messaging\Application\Action;

use Illuminate\Support\Collection;
use PactTrackSDK\SharedResources\Modules\Messaging\Application\Port\Repository\MessageRepository;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\Message;

/**
 * Every message on a matter, oldest first — backs
 * GET /api/v1/matters/{matter}/messages. Thin: the provider scoping and
 * eager-loading live in the repository (EloquentMessageRepository::
 * messagesForMatter), this only names the use case.
 *
 * The caller (MessageController) has already authorized `view` on the
 * Matter via the policy; passing provider_id here keeps the query itself
 * tenant-scoped too — a gate alone is never sufficient for an index (see
 * TenantScopedPolicy).
 */
class ListMatterMessagesAction
{
    public function __construct(private readonly MessageRepository $messages)
    {
    }

    /**
     * @return Collection<int, Message>
     */
    public function handle(int $providerId, int $matterId): Collection
    {
        return $this->messages->messagesForMatter($providerId, $matterId);
    }
}
