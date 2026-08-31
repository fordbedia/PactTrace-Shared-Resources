<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Infrastructure\Repositories\Eloquent;

use DateTimeInterface;
use PactTrackSDK\SharedResources\Modules\Signature\Application\Port\Repository\EnvelopeReadRepository;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Enums\EnvelopeStatus;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;

/**
 * @see EnvelopeReadRepository
 *
 * Queries through the `Envelope` model, so `BelongsToWorkspace`'s global
 * scope applies the same way it does for the Matter/Document dashboard
 * counts — one consistent workspace dimension across the whole dashboard,
 * not a special case here (see .claude/rules/workspace.md).
 */
class EloquentEnvelopeReadRepository implements EnvelopeReadRepository
{
    public function countCompletedBetween(int $providerId, DateTimeInterface $from, ?DateTimeInterface $to = null): int
    {
        return Envelope::query()
            ->where('provider_id', $providerId)
            ->where('status', EnvelopeStatus::Completed->value)
            ->where('completed_at', '>=', $from)
            ->when($to !== null, fn ($query) => $query->where('completed_at', '<', $to))
            ->count();
    }

    public function completedCountByDaySince(int $providerId, DateTimeInterface $since): array
    {
        return Envelope::query()
            ->where('provider_id', $providerId)
            ->where('status', EnvelopeStatus::Completed->value)
            ->where('completed_at', '>=', $since)
            ->selectRaw('DATE(completed_at) as day, COUNT(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day')
            ->map(static fn ($total): int => (int) $total)
            ->all();
    }
}
