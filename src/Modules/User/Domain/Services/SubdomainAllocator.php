<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Domain\Services;

use PactTrackSDK\SharedResources\Modules\User\Domain\Ports\SubdomainAvailability;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Subdomain;

/**
 * Finds a free subdomain near the one a provider wants.
 *
 * "Smith Law" is not a rare business name, so collisions are ordinary rather
 * than exceptional and the domain owns the answer to what happens next:
 * smith-law, then smith-law-2, smith-law-3, and so on.
 *
 * Framework-free — the only thing it touches is the SubdomainAvailability
 * port, so this is testable against an in-memory fake with no database.
 */
final class SubdomainAllocator
{
    /**
     * How far to walk the numbered variants before giving up on tidy names.
     *
     * Not a limit on registrations — past this point it falls back to a random
     * suffix, which always succeeds. It exists so a pathological case (fifty
     * providers all called "Smith Law") degrades to one extra query rather
     * than fifty.
     */
    private const MAX_SEQUENTIAL_ATTEMPTS = 50;

    public function __construct(
        private readonly SubdomainAvailability $availability,
    ) {
    }

    /**
     * Returns $desired if free, otherwise the first free variant of it.
     *
     * Racy in theory: two simultaneous registrations can both see the same
     * value as free. That is fine and deliberate — the UNIQUE index refuses
     * the second insert and the surrounding transaction rolls that whole
     * registration back, which is the correct outcome for a collision this
     * unlikely. Locking the namespace to close the window would cost every
     * registration to protect against almost none.
     */
    public function allocate(Subdomain $desired): Subdomain
    {
        if (! $this->availability->isTaken($desired)) {
            return $desired;
        }

        for ($n = 2; $n <= self::MAX_SEQUENTIAL_ATTEMPTS; $n++) {
            $candidate = $desired->withSuffix((string) $n);

            if (! $this->availability->isTaken($candidate)) {
                return $candidate;
            }
        }

        return $desired->withSuffix($this->randomSuffix());
    }

    private function randomSuffix(): string
    {
        return substr(bin2hex(random_bytes(4)), 0, 6);
    }
}
