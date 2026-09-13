<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects;

use InvalidArgumentException;

/**
 * A tenant's own custom domain (`portal.theirfirm.com`) — unlike
 * {@see Subdomain}, this is a full hostname the tenant already owns
 * elsewhere, not a single DNS label PactTrack allocates. Validates FQDN
 * grammar only; DNS verification (does it actually point at PactTrack) is a
 * separate, later step — see Domain\Ports\CustomDomainVerifier.
 *
 * Framework-free per the hexagonal rule in the top-level CLAUDE.md.
 */
final class CustomDomainName
{
    /** Maximum total hostname length (RFC 1035). */
    public const MAX_LENGTH = 253;

    private function __construct(
        public readonly string $value,
    ) {
    }

    public static function fromString(string $value): self
    {
        $value = strtolower(trim($value));
        $value = rtrim($value, '.');

        if ($value === '') {
            throw new InvalidArgumentException('Custom domain cannot be empty.');
        }

        if (strlen($value) > self::MAX_LENGTH) {
            throw new InvalidArgumentException(
                sprintf('Custom domain cannot exceed %d characters.', self::MAX_LENGTH)
            );
        }

        if (! str_contains($value, '.')) {
            throw new InvalidArgumentException(
                "[{$value}] is not a valid domain: it must have at least one dot (e.g. portal.yourfirm.com)."
            );
        }

        $labels = explode('.', $value);

        foreach ($labels as $label) {
            if ($label === '' || preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $label) !== 1) {
                throw new InvalidArgumentException(
                    "[{$value}] is not a valid domain: each part must use only letters, numbers and "
                    . 'hyphens, and cannot start or end with a hyphen.'
                );
            }
        }

        return new self($value);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
