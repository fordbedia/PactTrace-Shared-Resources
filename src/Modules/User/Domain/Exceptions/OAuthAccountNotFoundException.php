<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown by AuthenticateViaOAuth when a "Continue with Google/Microsoft"
 * click on /sign-in resolves to an email with no matching account.
 * Deliberate: /sign-in never silently creates an account (only /sign-up's
 * `?intent=register` does) — OAuthController catches this and redirects back
 * to /sign-in with an inline error pointing at sign-up instead.
 */
class OAuthAccountNotFoundException extends RuntimeException
{
}
