<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Infrastructure\Dns;

use RuntimeException;

/**
 * A DnsRecordReader could not complete the lookup at all (resolver
 * unreachable, timeout, malformed response) — distinct from "the record
 * simply isn't published yet", which is a normal empty/null return, not an
 * exception. Caught by DnsCustomDomainVerifier and translated into
 * CustomDomainVerificationResult::$error; never allowed to reach a
 * verifier's own caller.
 */
final class DnsLookupException extends RuntimeException
{
}
