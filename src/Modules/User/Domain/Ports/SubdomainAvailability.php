<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Domain\Ports;

use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Subdomain;

/**
 * Tells the domain whether a subdomain is already spoken for.
 *
 * Deliberately narrower than ProviderRepository: SubdomainAllocator needs to
 * ask exactly one question, and a domain service should not be handed a whole
 * repository (and with it the ability to write) to answer it.
 *
 * Implemented by EloquentProviderRepository — the `providers.subdomain` UNIQUE
 * index is the real authority; this is the pre-flight check.
 */
interface SubdomainAvailability
{
    public function isTaken(Subdomain $subdomain): bool;
}
