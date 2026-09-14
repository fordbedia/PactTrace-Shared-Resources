<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Notification\Application\Action;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use PactTrackSDK\SharedResources\Modules\Notification\Application\DTO\AuditLogListData;
use PactTrackSDK\SharedResources\Modules\Notification\Application\Ports\Repository\AuditLogRepository;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Plan;

/**
 * Thin — delegates straight to the repository, same shape as
 * ListMattersHandler. One filtered, paginated list is all this feature needs,
 * so there is no separate "listing service" indirection the way the Matter
 * module has (that split exists there for stat counts + several filter
 * variants; neither applies here).
 *
 * `$data->client_id`, when present, routes to `paginateForClient()` instead
 * of `paginateFiltered()` — the Client Detail page's Activity tab (see
 * .claude/rules/client.md), same optional-`client_id` pattern already
 * applied to the Matter/Document/Messaging modules' own listings. Every
 * other filter (actions/date range/search) and the retention cutoff below
 * apply identically either way.
 *
 * The one piece of logic it owns: turning the requesting tenant's `Plan` into
 * an audit-log retention cutoff. This is the only place `Plan`/`PlanInfo` is
 * consulted for this feature — the controller just resolves the enum, the
 * repository just applies a `where` on the cutoff it's handed. See
 * .claude/rules/plan.md ("audit log retention/export").
 */
class ListAuditLogsHandler
{
    public function __construct(private AuditLogRepository $repository)
    {
    }

    public function handle(AuditLogListData $data, Plan $plan): LengthAwarePaginator
    {
        $retentionCutoff = $this->retentionCutoff($plan);

        if ($data->client_id !== null) {
            return $this->repository->paginateForClient($data->provider_id, $data->client_id, $data, $retentionCutoff);
        }

        return $this->repository->paginateFiltered($data, $retentionCutoff);
    }

    /**
     * The `Y-m-d H:i:s` timestamp before which audit rows are hidden from the
     * listing for this plan, or null when the plan has unlimited retention
     * (Professional/Firm). Visibility only — see the port's docblock.
     */
    private function retentionCutoff(Plan $plan): ?string
    {
        $days = $plan->info()->auditLogRetentionDays;

        return $days === null
            ? null
            : Carbon::now()->subDays($days)->toDateTimeString();
    }
}
