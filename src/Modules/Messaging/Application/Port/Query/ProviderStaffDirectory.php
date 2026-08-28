<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Messaging\Application\Port\Query;

use Illuminate\Support\Collection;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * Read model behind the client portal's staff contact directory (see
 * .claude/rules/messaging.md, "Portal: staff contact directory"). Kept as
 * a query port rather than folded into MessageRepository — that aggregate
 * is MessageThread; this returns User rows — but it lives in the Messaging
 * module because starting a thread is its only purpose.
 */
interface ProviderStaffDirectory
{
    /**
     * Every provider-side user (owner + staff) belonging to the given
     * provider, ordered by name. Client-role users who happen to carry the
     * same `provider_id` are excluded — a client is never in another
     * client's directory.
     *
     * @return Collection<int, User>
     */
    public function forProvider(int $providerId): Collection;
}
