<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Infrastructure\Docusign;

/**
 * The result of one JWT Grant authentication — an access token plus the
 * account-specific eSignature API base URI DocuSign resolved it against
 * (this can differ per account/datacenter, so it's read from
 * /oauth/userinfo rather than assumed). Lives only for the duration of a
 * single request; never cached or persisted — see JwtGrantAuthenticator.
 */
final class DocusignSession
{
    public function __construct(
        public readonly string $accessToken,
        public readonly string $baseUri,
        public readonly string $accountId,
    ) {
    }
}
