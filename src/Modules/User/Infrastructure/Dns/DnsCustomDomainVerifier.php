<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Infrastructure\Dns;

use PactTrackSDK\SharedResources\Modules\User\Domain\Ports\CustomDomainVerifier;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\CustomDomainVerificationResult;

/**
 * The real CustomDomainVerifier adapter. Checks two independent records:
 *
 *  - a TXT record at `_pacttrack-verify.{domain}` equal to the tenant's own
 *    stored verification token (proves they control the domain's DNS);
 *  - a CNAME record at `{domain}` resolving to the platform's stable
 *    target (`$target`, from `config('branding.custom_domain_target')` —
 *    proves the domain is actually routed at PactTrack).
 *
 * Never throws — a DnsLookupException from the underlying DnsRecordReader is
 * caught and reported on the result's `$error` field instead, per
 * CustomDomainVerifier's own contract.
 */
final class DnsCustomDomainVerifier implements CustomDomainVerifier
{
    public function __construct(
        private readonly DnsRecordReader $reader,
        private readonly string $target,
        private readonly string $verificationPrefix = '_pacttrack-verify',
    ) {
    }

    public function verify(string $domain, string $expectedToken): CustomDomainVerificationResult
    {
        $txtHost = "{$this->verificationPrefix}.{$domain}";

        try {
            $txtMatched = in_array($expectedToken, $this->reader->txtRecords($txtHost), true);
        } catch (DnsLookupException $e) {
            return new CustomDomainVerificationResult(false, false, null, $e->getMessage());
        }

        try {
            $cnameTarget = $this->reader->cnameTarget($domain);
        } catch (DnsLookupException $e) {
            return new CustomDomainVerificationResult($txtMatched, false, null, $e->getMessage());
        }

        $cnameMatched = $cnameTarget !== null
            && strtolower(rtrim($cnameTarget, '.')) === strtolower(rtrim($this->target, '.'));

        return new CustomDomainVerificationResult($txtMatched, $cnameMatched, $cnameTarget);
    }
}
