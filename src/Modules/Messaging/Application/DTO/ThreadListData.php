<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Messaging\Application\DTO;

use Illuminate\Http\Request;

/**
 * The input to ListProviderThreadsAction — one page of the messages inbox
 * for the signed-in provider. Parsed once here so neither the action nor
 * the repository ever sees an Illuminate\Http\Request, matching the
 * Document module's DocumentListData.
 *
 * `filter` is normalised to exactly `all` or `unread` (the only two tabs —
 * "Archived" is gone, see .claude/rules/messaging.md); anything else falls
 * back to `all`. `per_page` is clamped to 1..100 rather than trusted — an
 * unbounded `?per_page=100000` would defeat the point of paginating an
 * inbox that grows without limit, same rule as DocumentListData.
 */
final readonly class ThreadListData
{
    public const FILTER_ALL = 'all';

    public const FILTER_UNREAD = 'unread';

    private const PER_PAGE_DEFAULT = 15;

    private const PER_PAGE_MAX = 100;

    public function __construct(
        public int $provider_id,
        public int $current_user_id,
        public string $filter,
        public int $per_page,
        public ?int $page,
    ) {
    }

    public static function fromRequest(Request $request, int $provider_id, int $current_user_id): self
    {
        $filter = (string) $request->query('filter', self::FILTER_ALL);

        return new self(
            provider_id: $provider_id,
            current_user_id: $current_user_id,
            filter: $filter === self::FILTER_UNREAD ? self::FILTER_UNREAD : self::FILTER_ALL,
            per_page: max(1, min(self::PER_PAGE_MAX, (int) $request->query('per_page', self::PER_PAGE_DEFAULT))),
            page: $request->filled('page') ? (int) $request->query('page') : null,
        );
    }

    public function isUnreadOnly(): bool
    {
        return $this->filter === self::FILTER_UNREAD;
    }
}
