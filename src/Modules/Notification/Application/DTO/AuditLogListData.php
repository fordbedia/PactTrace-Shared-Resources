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
 * A client filter and a matter filter are deliberately absent: `audit_logs`
 * has no `client_id`/`matter_id` column, `auditable_type` is never `Matter`,
 * and no consistent metadata convention links a row to either — a filter that
 * looked like it worked but quietly under-filtered would be worse than none
 * on a compliance surface.
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
