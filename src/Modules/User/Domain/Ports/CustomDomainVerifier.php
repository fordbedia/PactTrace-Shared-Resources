<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Domain\Ports;

use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\CustomDomainVerificationResult;

/**
 * Checks whether a tenant's custom domain is actually pointed at PactTrack:
 * a TXT record at `_pacttrack-verify.{domain}` matching the stored
 * verification token (proves ownership), and a CNAME record at `{domain}`
 * resolving to the platform's stable custom-domain target
 * (`config('branding.custom_domain_target')`, proves routing).
 *
 * Implemented by Infrastructure\Dns\DnsCustomDomainVerifier (real DNS
 * lookups) — bound in UserProvider. Tests fake this port directly rather
 * than hitting real DNS (see CustomDomainVerifierTest, which instead fakes
 * the adapter's own lower-level DnsRecordReader collaborator).
 */
interface CustomDomainVerifier
{
    public function verify(string $domain, string $expectedToken): CustomDomainVerificationResult;
}
