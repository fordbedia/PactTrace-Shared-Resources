<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports;

use PactTrackSDK\SharedResources\Modules\User\Models\Provider;

/**
 * Port for persisting the tenant record.
 *
 * Implemented by Infrastructure\Repositories\Eloquent\EloquentProviderRepository,
 * which also implements Domain\Ports\SubdomainAvailability — availability lives
 * on that separate, narrower port so the domain's SubdomainAllocator can ask
 * its one question without being handed write access to the whole table.
 */
interface ProviderRepository
{
    public function create(array $data): Provider;

    /** Persist an already-resolved, mutated Provider instance. */
    public function save(Provider $provider): Provider;

    /**
     * Whether `$subdomain` is already registered by a provider OTHER than
     * `$exceptProviderId` — the availability check for the branding screen,
     * where the tenant's own current subdomain must not report as taken.
     */
    public function subdomainTakenByAnother(string $subdomain, int $exceptProviderId): bool;

    /**
     * Whether `$domain` is already claimed as a `custom_domain` by a
     * provider OTHER than `$exceptProviderId` — same shape as
     * {@see self::subdomainTakenByAnother()}, for the Custom Domain save
     * endpoint. `custom_domain` is DB-unique, but this lets the use case
     * report a friendly 422 instead of a raw constraint-violation 500.
     */
    public function customDomainTakenByAnother(string $domain, int $exceptProviderId): bool;

    /**
     * Every provider currently mid-verification — the hourly reconciliation
     * job's input set (see Application\UseCases\Branding\ReconcilePendingCustomDomains).
     *
     * @return iterable<Provider>
     */
    public function withPendingCustomDomainVerification(): iterable;

    /**
     * The provider (if any) whose `custom_domain` equals `$host` and whose
     * verification has actually succeeded — one of the two host-resolution
     * strategies the request-time middleware tries (see
     * Http\Middleware\ResolveProviderFromHost).
     */
    public function findByVerifiedCustomDomain(string $host): ?Provider;

    /**
     * The provider (if any) registered under `$subdomain` — the platform's
     * own `{subdomain}.pacttrack.com` host-resolution strategy, the sibling
     * of {@see self::findByVerifiedCustomDomain()}. `$subdomain` is a bare
     * DNS label (e.g. `contislawfirm`), already validated by the caller
     * (Http\Middleware\ResolveProviderFromHost only calls this for a label
     * that passed Domain\ValueObjects\Subdomain::isValidLabel()) — no
     * verification lifecycle applies here the way it does for a custom
     * domain, since a subdomain is provisioned by the platform itself, not
     * pointed at it by the tenant's own DNS.
     */
    public function findBySubdomain(string $subdomain): ?Provider;
}
