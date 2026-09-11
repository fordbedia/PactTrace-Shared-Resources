<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects;

use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * What AuthenticateViaOAuth::handle() hands back to OAuthController — the
 * signed-in user, plus whether this call is the one that just created their
 * account. OAuthController needs `created` to decide the post-login
 * destination: a brand-new owner still has onboarding step 2
 * (`/dashboard/create-workspace?onboarding=1`) to finish, same as the
 * password sign-up path (see RegisterProvider / handleSignUpSubmit).
 */
final class OAuthAuthenticationResult
{
    private function __construct(
        public readonly User $user,
        public readonly bool $created,
    ) {
    }

    public static function signedIn(User $user): self
    {
        return new self($user, created: false);
    }

    public static function registered(User $user): self
    {
        return new self($user, created: true);
    }
}
