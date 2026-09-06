<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects;

/**
 * One dimension a plan change is blocked on — {@see PlanChangeResult} carries
 * a list of these so an owner sees every failing dimension in one response
 * instead of fixing storage, retrying, and only then learning about seats.
 * See .claude/rules/plan.md, "Downgrade / over-limit policy".
 */
final class PlanChangeBlocker
{
    public function __construct(
        /** 'seats' | 'clients' | 'storage' — matches the frontend's switch on it. */
        public readonly string $dimension,
        public readonly int $current,
        public readonly int $limit,
        public readonly string $message,
    ) {
    }

    /**
     * @return array{dimension: string, current: int, limit: int, message: string}
     */
    public function toArray(): array
    {
        return [
            'dimension' => $this->dimension,
            'current' => $this->current,
            'limit' => $this->limit,
            'message' => $this->message,
        ];
    }
}
