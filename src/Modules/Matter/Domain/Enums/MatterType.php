<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Matter\Domain\Enums;

/**
 * The kind of engagement a Matter represents — a per-matter classification,
 * distinct from `status` (active/on_hold/completed/cancelled). See
 * .claude/rules/matter.md, "Matter Type and Edit Matter".
 *
 * The four values were carried over unchanged from the decorative "Matter
 * Type" select that previously lived (unwired) on the Upload Documents modal
 * — nothing new was invented beyond what the artboard already implied.
 * Framework-free by design (hexagonal rule in the top-level CLAUDE.md) — this
 * is domain vocabulary, not a spatie/Eloquent concern.
 */
enum MatterType: string
{
    case Agreement = 'agreement';
    case Letter = 'letter';
    case Contract = 'contract';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Agreement => 'Agreement',
            self::Letter => 'Letter',
            self::Contract => 'Contract',
            self::Other => 'Other',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
