<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Workspace\Domain\ValueObjects;

/**
 * The kind of practice a workspace represents.
 *
 * This exists only to pick a starting set of labels. It deliberately carries no
 * behaviour beyond that: nothing in the product should branch on the type to
 * decide what a provider *may do*, because the whole point of the Workspace
 * concept is that a workspace is a legal practice or an accounting practice
 * only in its wording. Availability is identical on every plan and every type.
 *
 * Framework-free by design (hexagonal rule in CLAUDE.md) — the string stored in
 * `workspaces.workspace_type` is an adapter detail, not the definition.
 */
enum WorkspaceType: string
{
    case Legal = 'legal';
    case Accounting = 'accounting';
    case Consulting = 'consulting';
    case General = 'general';

    /**
     * The type used when none is chosen, and the fallback whenever a preset
     * lookup cannot resolve anything better.
     */
    public static function default(): self
    {
        return self::General;
    }

    /**
     * Every value, for validation rules, the migration's enum and test
     * assertions.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Legal => 'Legal practice',
            self::Accounting => 'Accounting practice',
            self::Consulting => 'Consulting practice',
            self::General => 'General',
        };
    }
}
