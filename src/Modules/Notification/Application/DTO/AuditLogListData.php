<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Notification\Application\DTO;

use Illuminate\Http\Request;

/**
 * Query surface for `GET /api/v1/audit-logs` — see
 * .claude/rules/notification.md.
 *
 * `provider_id` is taken from the authenticated user by the controller, never
 * from request input — `AuditLogPolicy::viewAny()` only checks the permission
 * (there is no row to scope against yet), so the repository query is what has
 * to enforce tenant isolation.
 *
 * Only filters that are honest against the real `audit_logs` schema are
 * carried here:
 *
 *  - `actions` — zero or more exact `action` strings (dot-notation, e.g.
 *    `document.archived`). There is no fixed catalogue; the frontend's filter
 *    options come from GET /audit-logs/action-types (distinct values actually
 *    present for the tenant).
 *  - `from` / `to` — an inclusive `created_at` date range (Y-m-d). `to` is
 *    widened to end-of-day so the last day is included.
 *  - `search` — a LIKE over `action` and the actor's name.
 *
 * A matter filter is deliberately absent: `audit_logs` has no `matter_id`
 * column, and no single `whereHasMorph` target list (unlike client scoping
 * below) would honestly capture "every row about this matter" — a filter
 * that looked like it worked but quietly under-filtered would be worse than
 * none on a compliance surface.
 *
 * `client_id` is the one exception, added for the Client Detail page's
 * Activity tab (see .claude/rules/client.md) — `audit_logs` still has no
 * `client_id` column, but `AuditLogRepository::paginateForClient()` resolves
 * it correctly via the same `whereHasMorph` scoping (across Matter,
 * Document, Envelope and MessageThread) that `recentForClient()` already
 * uses for that page's Overview tab, so this one filter is honest against
 * the schema despite the column not existing directly.
 */
final readonly class AuditLogListData
{
    /**
     * @param list<string> $actions
     */
    public function __construct(
        public int $provider_id,
        public array $actions,
        public ?string $from,
        public ?string $to,
        public ?string $search,
        public int $per_page,
        public ?int $page,
        public ?int $client_id = null,
    ) {
    }

    public static function fromRequest(Request $request, int $providerId): self
    {
        $actions = $request->query('actions', []);
        if (is_string($actions)) {
            $actions = $actions === '' ? [] : explode(',', $actions);
        }
        $actions = array_values(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            is_array($actions) ? $actions : [],
        ), static fn (string $value): bool => $value !== ''));

        $search = $request->query('search');
        $search = is_string($search) && trim($search) !== '' ? trim($search) : null;

        $perPage = (int) $request->query('per_page', 20);
        $perPage = max(1, min($perPage, 100));

        return new self(
            $providerId,
            $actions,
            self::normalizeDate($request->query('from')),
            self::normalizeDate($request->query('to')),
            $search,
            $perPage,
            $request->filled('page') ? (int) $request->query('page') : null,
            $request->filled('client_id') ? (int) $request->query('client_id') : null,
        );
    }

    private static function normalizeDate(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : date('Y-m-d', $timestamp);
    }
}
