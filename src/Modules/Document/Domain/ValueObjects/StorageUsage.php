<?php

declare(strict_types=1);

namespace PactTraceSDK\SharedResources\Modules\Document\Domain\ValueObjects;

use InvalidArgumentException;

/**
 * How much of a tenant's storage allowance is consumed — the value behind the
 * STORAGE indicator on /dashboard/documents (see .claude/rules/document.md).
 *
 * Framework-free per the hexagonal rule in the top-level CLAUDE.md: it holds
 * two byte counts and the arithmetic between them, and knows nothing about
 * where either number came from (a `SUM(size)` query, config, a Stripe plan).
 */
final readonly class StorageUsage
{
    public function __construct(
        public int $usedBytes,
        public int $limitBytes,
    ) {
        // Negative bytes are not "an edge case to round off" — they mean the
        // caller computed something wrong, and silently clamping would hide
        // it behind a plausible-looking progress bar.
        if ($usedBytes < 0 || $limitBytes < 0) {
            throw new InvalidArgumentException('Storage usage cannot be negative.');
        }
    }

    /**
     * Percentage of the allowance consumed, 0-100, rounded to one decimal.
     *
     * Clamped at 100 so the progress bar can use this width directly and
     * cannot overflow its track — `usedBytes` legitimately exceeds the limit
     * when nothing enforces the quota at upload time, which is the case
     * today. Returns 0.0 for an unlimited/unknown allowance (limit of 0)
     * rather than dividing by zero.
     */
    public function percentage(): float
    {
        if ($this->limitBytes <= 0) {
            return 0.0;
        }

        return round(min(100, $this->usedBytes / $this->limitBytes * 100), 1);
    }

    public function remainingBytes(): int
    {
        return max(0, $this->limitBytes - $this->usedBytes);
    }

    public function isOverLimit(): bool
    {
        return $this->limitBytes > 0 && $this->usedBytes > $this->limitBytes;
    }
}
