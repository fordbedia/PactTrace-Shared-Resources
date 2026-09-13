<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects;

/**
 * `providers.custom_domain_status` — the lifecycle of a tenant's custom
 * domain, independent of `custom_domain_ssl_status` (Cloudflare's own TLS
 * provisioning state, tracked separately — see the 2026-09-13 migration).
 *
 * Framework-free per the hexagonal rule in the top-level CLAUDE.md, same
 * shape as Plan / WorkspaceType.
 */
enum CustomDomainStatus: string
{
    /** No domain saved, or the domain string was just cleared. */
    case Unverified = 'unverified';

    /** A domain is saved; DNS verification has not (yet) fully passed. */
    case Pending = 'pending';

    /** Both the TXT and CNAME checks passed. */
    case Verified = 'verified';

    /**
     * A verification attempt hit a genuine DNS resolution error (not merely
     * "the records aren't there yet") — distinct from Pending, which is the
     * normal "provider hasn't finished their DNS setup" state.
     */
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Unverified => 'Unverified',
            self::Pending => 'Pending',
            self::Verified => 'Verified',
            self::Failed => 'Failed',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
