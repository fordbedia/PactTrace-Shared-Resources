<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects;

use InvalidArgumentException;

/**
 * What a "Continue with Google/Microsoft" callback resolves to, once
 * OAuthController has pulled it out of Socialite's own user object.
 * Framework-free by design — this is what lets AuthenticateViaOAuth stay
 * ignorant of Socialite/Laravel entirely and be exercised against a plain
 * value in a test.
 */
final class OAuthIdentity
{
    public const GOOGLE = 'google';

    public const MICROSOFT = 'microsoft';

    /**
     * @param  string  $provider    One of self::GOOGLE / self::MICROSOFT.
     * @param  string  $externalId  The provider's own, stable account id
     *                              (Socialite's `getId()`) — never the email,
     *                              which a user could change on the provider
     *                              side.
     * @param  string  $email       Provider-verified — Google and Microsoft
     *                              (via Entra ID) both only return a
     *                              verified address here.
     * @param  string  $name        Display name; falls back to the email's
     *                              local part when a provider omits it (seen
     *                              on some Microsoft personal accounts).
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $externalId,
        public readonly string $email,
        public readonly string $name,
    ) {
        if (! in_array($provider, [self::GOOGLE, self::MICROSOFT], true)) {
            throw new InvalidArgumentException("Unsupported OAuth provider [{$provider}].");
        }
    }

    /**
     * The `users` column this identity links against.
     */
    public function userColumn(): string
    {
        return match ($this->provider) {
            self::GOOGLE => 'google_id',
            self::MICROSOFT => 'microsoft_id',
        };
    }
}
