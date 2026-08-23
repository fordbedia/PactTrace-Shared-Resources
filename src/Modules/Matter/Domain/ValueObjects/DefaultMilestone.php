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
 */
final class DefaultMilestone
{
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
        $names = ['Engagement', 'Discovery', 'Drafting', 'Review', 'Completed'];

        return array_map(
            static fn (int $position, string $name): self => new self($name, $position),
            array_keys($names),
            $names,
        );
    }
}
