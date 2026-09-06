<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Client\Infrastructure\Repositories\Eloquent;

use PactTrackSDK\SharedResources\Modules\Client\Application\Ports\Query\ClientNotificationSignalReader;
use PactTrackSDK\SharedResources\Modules\Client\Models\Client;
use PactTrackSDK\SharedResources\Modules\Matter\Models\Matter;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\MessageThread;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Enums\EnvelopeStatus;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\Modules\Workspace\Models\Scopes\WorkspaceScope;

/**
 * Counts each bell signal in SQL.
 *
 * The Envelope and Matter reads drop `WorkspaceScope`: a portal request may
 * carry a narrower workspace context, but the bell answers "across everything
 * this client has with this provider". Tenancy is still enforced upstream —
 * the client id itself is resolved from the acting user's own `Client` row.
 * SoftDeletes scopes are left on, so archived threads / trashed rows don't
 * count. Same shape as `EloquentAccountDeletionSignals`.
 */
final class EloquentClientNotificationSignalReader implements ClientNotificationSignalReader
{
    private const NEW_MATTER_WINDOW_DAYS = 7;

    public function unreadMessageThreadCount(int $clientId): int
    {
        $clientUserId = Client::query()->whereKey($clientId)->value('user_id');

        if ($clientUserId === null) {
            return 0;
        }

        return MessageThread::query()
            ->where('client_id', $clientId)
            ->withUnreadFor((int) $clientUserId)
            ->count();
    }

    public function documentsAwaitingSignatureCount(int $clientId): int
    {
        return Envelope::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->where('client_id', $clientId)
            ->whereIn('status', [
                EnvelopeStatus::Sent->value,
                EnvelopeStatus::PartiallySigned->value,
            ])
            ->count();
    }

    public function newMatterCount(int $clientId): int
    {
        return Matter::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->where('client_id', $clientId)
            ->where('created_at', '>=', now()->subDays(self::NEW_MATTER_WINDOW_DAYS))
            ->count();
    }
}
