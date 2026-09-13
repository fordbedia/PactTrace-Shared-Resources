<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Client\Application\UseCases;

use PactTrackSDK\SharedResources\Modules\Client\Application\Ports\Query\ClientNotificationSignalReader;
use PactTrackSDK\SharedResources\Modules\Client\Application\Ports\Query\ClientOverviewReader;
use PactTrackSDK\SharedResources\Modules\Client\Domain\ValueObjects\ClientOverviewSummary;
use PactTrackSDK\SharedResources\Modules\Notification\Application\Ports\Repository\AuditLogRepository;

/**
 * Assembles the one {@see ClientOverviewSummary} the Client Detail page's
 * Overview tab (`GET /clients/{client}/overview`) reads — same
 * orchestration shape as {@see GetClientNotificationSummary}: a
 * client-scoped cross-module read lives here, in the Client module, because
 * it's the `Client` that ties Matters/Documents/Envelopes/Messages/Activity
 * together and none of those modules has an existing "for one client"
 * summary to extend.
 *
 * Reuses {@see ClientNotificationSignalReader::documentsAwaitingSignatureCount()}
 * for the envelope stat card's "awaiting signature" figure rather than
 * having {@see ClientOverviewReader} grow a second, identical query — one
 * definition of "awaiting signature" for this client, not two that could
 * drift apart.
 */
final class GetClientOverview
{
    private const RECENT_MATTERS_LIMIT = 5;
    private const RECENT_ACTIVITY_LIMIT = 8;

    public function __construct(
        private readonly ClientOverviewReader $overview,
        private readonly ClientNotificationSignalReader $signals,
        private readonly AuditLogRepository $auditLogs,
    ) {
    }

    public function handle(int $providerId, int $clientId, int $staffUserId): ClientOverviewSummary
    {
        return new ClientOverviewSummary(
            activeMatterCount: $this->overview->activeMatterCount($clientId),
            closedMatterCount: $this->overview->closedMatterCount($clientId),
            documentCount: $this->overview->documentCount($clientId),
            documentsOutForSignatureCount: $this->overview->documentsOutForSignatureCount($clientId),
            envelopeCount: $this->overview->envelopeCount($clientId),
            envelopesAwaitingSignatureCount: $this->signals->documentsAwaitingSignatureCount($clientId),
            messageThreadCount: $this->overview->messageThreadCount($clientId),
            unreadMessageThreadCount: $this->overview->unreadMessageThreadCountForStaff($clientId, $staffUserId),
            openMatters: $this->overview->recentMatters($clientId, self::RECENT_MATTERS_LIMIT),
            recentActivity: $this->auditLogs->recentForClient($providerId, $clientId, self::RECENT_ACTIVITY_LIMIT)
                ->map(static fn ($log): array => [
                    'id' => $log->id,
                    'action' => $log->action,
                    'auditable_type' => $log->auditable_type !== null
                        ? (fn (string $fqcn) => substr($fqcn, ((int) strrpos($fqcn, '\\')) + 1))($log->auditable_type)
                        : null,
                    'at' => $log->created_at?->toIso8601String(),
                ])
                ->all(),
        );
    }
}
