<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Infrastructure\Dns;

use Throwable;

/**
 * The real adapter — wraps `dns_get_record()`. `false` is that function's own
 * "the query failed" signal (resolver unreachable, timeout) and is
 * translated into a {@see DnsLookupException}; a genuinely empty result
 * (`[]`, no matching records) is returned as-is. A `Throwable` escaping the
 * call itself (e.g. an `ErrorException` bridged from an E_WARNING under a
 * strict error handler) is treated the same way.
 */
final class NativeDnsRecordReader implements DnsRecordReader
{
    public function txtRecords(string $host): array
    {
        try {
            $records = @dns_get_record($host, DNS_TXT);
        } catch (Throwable $e) {
            throw new DnsLookupException("DNS TXT lookup for [{$host}] failed: {$e->getMessage()}", 0, $e);
        }

        if ($records === false) {
            throw new DnsLookupException("DNS TXT lookup for [{$host}] failed.");
        }

        $values = [];
        foreach ($records as $record) {
            if (isset($record['txt']) && is_string($record['txt'])) {
                $values[] = $record['txt'];
            }
        }

        return $values;
    }

    public function cnameTarget(string $host): ?string
    {
        try {
            $records = @dns_get_record($host, DNS_CNAME);
        } catch (Throwable $e) {
            throw new DnsLookupException("DNS CNAME lookup for [{$host}] failed: {$e->getMessage()}", 0, $e);
        }

        if ($records === false) {
            throw new DnsLookupException("DNS CNAME lookup for [{$host}] failed.");
        }

        if ($records === []) {
            return null;
        }

        $target = $records[0]['target'] ?? null;

        return is_string($target) ? rtrim($target, '.') : null;
    }
}
