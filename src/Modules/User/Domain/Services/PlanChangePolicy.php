<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Domain\Services;

use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Plan;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\PlanChangeBlocker;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\PlanChangeResult;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\PlanUsageSummary;

/**
 * The downgrade/upgrade usage pre-flight — the one check Stripe itself
 * cannot run, since it has no notion of PactTrack's own seat/client/storage
 * counts. See .claude/rules/plan.md, "Downgrade / over-limit policy".
 *
 * Checks only the three *stock* limits (a running total that never resets):
 * seats, active clients, storage. `maxEnvelopesPerMonth` is deliberately
 * excluded — it's a *flow* limit that resets every billing cycle, so a
 * downgrade never needs to pre-check it (see {@see PlanPolicy}'s own
 * docblock for the same stock/flow distinction).
 *
 * Framework-free by the hexagonal rule in CLAUDE.md — same shape as
 * {@see PlanPolicy}: a decision, not a closed set of values, so a class
 * rather than an enum.
 */
final class PlanChangePolicy
{
    public function evaluate(Plan $target, PlanUsageSummary $usage): PlanChangeResult
    {
        $limits = $target->info();
        $blockers = [];

        if ($limits->maxSeats !== null && $usage->activeStaffCount > $limits->maxSeats) {
            $blockers[] = new PlanChangeBlocker(
                dimension: 'seats',
                current: $usage->activeStaffCount,
                limit: $limits->maxSeats,
                message: "You have {$usage->activeStaffCount} active staff; {$limits->label} allows {$limits->maxSeats} "
                    . ($limits->maxSeats === 1 ? 'seat' : 'seats') . '. Deactivate staff before downgrading.',
            );
        }

        if ($limits->maxActiveClients !== null && $usage->activeClientCount > $limits->maxActiveClients) {
            $blockers[] = new PlanChangeBlocker(
                dimension: 'clients',
                current: $usage->activeClientCount,
                limit: $limits->maxActiveClients,
                message: "You have {$usage->activeClientCount} active clients; {$limits->label} allows {$limits->maxActiveClients}. Remove or archive clients before downgrading.",
            );
        }

        if ($usage->storageUsedBytes > $limits->storageLimitBytes) {
            $blockers[] = new PlanChangeBlocker(
                dimension: 'storage',
                current: $usage->storageUsedBytes,
                limit: $limits->storageLimitBytes,
                message: "You're using more storage than {$limits->label} allows ({$limits->storageLimitLabel}). Delete or archive documents before downgrading.",
            );
        }

        return $blockers === [] ? PlanChangeResult::allowed() : PlanChangeResult::blocked($blockers);
    }
}
