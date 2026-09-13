<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Infrastructure\Dns;

/**
 * Thin seam around PHP's `dns_get_record()` — an internal collaborator of
 * DnsCustomDomainVerifier, not a Domain port (nothing outside this
 * Infrastructure directory needs to know DNS lookups exist at all). Exists
 * only so unit tests can inject a fake resolver instead of hitting real DNS.
 */
interface DnsRecordReader
{
    /**
     * Every TXT record value published at `$host` — an empty array is a
     * legitimate "no such record (yet)" answer.
     *
     * @throws DnsLookupException when the resolver itself could not complete
     *         the query (unreachable, timeout, malformed response) — a real
     *         failure, distinct from an empty result.
     *
     * @return list<string>
     */
    public function txtRecords(string $host): array;

    /**
     * The CNAME target `$host` resolves to (trailing dot stripped), or null
     * for a legitimate "no CNAME record" answer.
     *
     * @throws DnsLookupException on a genuine resolver failure — see
     *         {@see self::txtRecords()}.
     */
    public function cnameTarget(string $host): ?string;
}
