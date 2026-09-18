<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Matter\Domain\ValueObjects;

/**
 * The default milestone set every new Matter is seeded with — see
 * Application\Services\DefaultMilestoneSeeder and .claude/rules/matter.md,
 * "Matter Progress timeline". Framework-free per the hexagonal rule in the
 * top-level CLAUDE.md: this is pure ordering/naming data, no Illuminate
 * dependency.
 *
 * The terminal entry is deliberately named "Completed", not "Execution" —
 * the latter was a stale label from an earlier artboard mockup
 * (Dashboard/client-portal.html) and must not be reintroduced.
 *
 * **"Discovery" was removed 2026-09-17** — it had no real action behind it
 * anywhere in the product (no checkbox, no upload, no status change ever
 * advanced it) and nothing in `MilestoneProgressionService` ever completed
 * it, so it sat `pending` forever on every matter and silently capped
 * `MatterProgressCalculator` at 80%. See .claude/rules/matter.md, "Matter
 * Progress timeline" for the full history and the migration that removed
 * already-existing "Discovery" rows. Do not reintroduce it without also
 * wiring a real completion trigger for it.
 */
final class DefaultMilestone
{
    /**
     * Named constants so other modules advancing a milestone (see
     * Application\Services\MilestoneProgressionService) reference a single
     * source of truth instead of a magic string.
     */
    public const ENGAGEMENT = 'Engagement';

    public const DRAFTING = 'Drafting';

    public const REVIEW = 'Review';

    public const COMPLETED = 'Completed';

    private function __construct(
        public readonly string $name,
        public readonly int $position,
    ) {
    }

    /**
     * @return list<self>
     */
    public static function ordered(): array
    {
        $names = [self::ENGAGEMENT, self::DRAFTING, self::REVIEW, self::COMPLETED];

        return array_map(
            static fn (int $position, string $name): self => new self($name, $position),
            array_keys($names),
            $names,
        );
    }
}
