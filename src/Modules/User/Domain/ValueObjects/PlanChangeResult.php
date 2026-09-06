<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects;

/**
 * The outcome of {@see \PactTrackSDK\SharedResources\Modules\User\Domain\Services\PlanChangePolicy}
 * evaluating a downgrade/upgrade pre-flight. Same "self-contained result"
 * shape as {@see PlanGateResult} — the frontend renders every blocker's
 * message directly, no second request needed.
 */
final class PlanChangeResult
{
    /**
     * @param  list<PlanChangeBlocker>  $blockers
     */
    private function __construct(
        public readonly bool $allowed,
        public readonly array $blockers,
    ) {
    }

    public static function allowed(): self
    {
        return new self(true, []);
    }

    /**
     * @param  list<PlanChangeBlocker>  $blockers
     */
    public static function blocked(array $blockers): self
    {
        return new self(false, $blockers);
    }

    /**
     * @return array{allowed: bool, blockers: list<array{dimension: string, current: int, limit: int, message: string}>}
     */
    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'blockers' => array_map(static fn (PlanChangeBlocker $b): array => $b->toArray(), $this->blockers),
        ];
    }
}
