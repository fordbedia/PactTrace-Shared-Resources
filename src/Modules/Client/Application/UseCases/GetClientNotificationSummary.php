<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Client\Application\UseCases;

use PactTrackSDK\SharedResources\Modules\Client\Application\Ports\Query\ClientNotificationSignalReader;
use PactTrackSDK\SharedResources\Modules\Client\Domain\ValueObjects\ClientNotificationSummary;

/**
 * Assembles the one {@see ClientNotificationSummary} the client-portal
 * notification bell (`GET /api/v1/portal/notifications`) reads — same
 * orchestration shape as `GetPlanUsageSummary` in the User module.
 *
 * A client-scoped cross-module read lives here, in the Client module, rather
 * than in Messaging / Signature / Matter individually: it's the Client that
 * ties the three signals together, and none of those modules has an existing
 * "for one client, across all their matters" query to extend.
 */
final class GetClientNotificationSummary
{
    public function __construct(
        private readonly ClientNotificationSignalReader $signals,
    ) {
    }

    public function handle(int $clientId): ClientNotificationSummary
    {
        return new ClientNotificationSummary(
            unreadMessageThreadCount: $this->signals->unreadMessageThreadCount($clientId),
            documentsAwaitingSignatureCount: $this->signals->documentsAwaitingSignatureCount($clientId),
            newMatterCount: $this->signals->newMatterCount($clientId),
        );
    }
}
