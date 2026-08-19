<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown by JwtGrantAuthenticator the first time an impersonated user hasn't
 * yet granted consent for the integration's scopes — JWT Grant fails with
 * this until a human (the impersonated system user) visits DocuSign's
 * consent URL once, interactively, one time ever. Not a per-request auth
 * failure: surface this clearly (log it, or bubble to an admin-facing error)
 * rather than retrying, since retrying an un-consented request always fails
 * the same way.
 */
class DocusignConsentRequiredException extends RuntimeException
{
    public static function forClientId(string $clientId, string $authServer, string $redirectUri): self
    {
        $consentUrl = sprintf(
            'https://%s/oauth/auth?response_type=code&scope=signature%%20impersonation&client_id=%s&redirect_uri=%s',
            $authServer,
            urlencode($clientId),
            urlencode($redirectUri),
        );

        return new self(
            'DocuSign JWT Grant requires one-time consent from the impersonated user before it will work. '
            . "Visit this URL once, signed in as the impersonated account, to grant it: {$consentUrl}",
        );
    }
}
