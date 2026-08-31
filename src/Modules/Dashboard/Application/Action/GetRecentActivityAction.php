<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Dashboard\Application\Action;

use Illuminate\Database\Eloquent\Collection;
use PactTrackSDK\SharedResources\Modules\Notification\Application\Ports\Repository\AuditLogRepository;

/**
 * The "Recent Activity" timeline on `/dashboard` — the tenant's newest audit
 * events.
 *
 * Deliberately a thin, small-limit call into the existing Notification
 * module's AuditLogRepository (the same table and tenant scoping
 * `/dashboard/audit-log` already uses), NOT a second activity-feed
 * implementation and NOT the portal's multi-source MatterActivityFeedBuilder
 * (that merge is per-matter and would need every matter's
 * documents/envelopes/signers/milestones eager-loaded to run provider-wide).
 * The audit log already records every document/envelope/subscription action
 * with an actor, a timestamp and metadata — that is exactly this feed.
 */
final class GetRecentActivityAction
{
    /** Entries the timeline shows — matches the artboard. */
    public const LIMIT = 5;

    public function __construct(private readonly AuditLogRepository $auditLogs)
    {
    }

    /**
     * @return Collection<int, \PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog>
     */
    public function handle(int $providerId, int $limit = self::LIMIT): Collection
    {
        return $this->auditLogs->recentForProvider($providerId, $limit);
    }
}
