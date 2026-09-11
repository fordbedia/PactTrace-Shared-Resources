<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Auth;

use Illuminate\Support\Str;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\UserRepository;
use PactTrackSDK\SharedResources\Modules\User\Application\Services\UserAuthentication;
use PactTrackSDK\SharedResources\Modules\User\Application\Services\UserHintCookie;
use PactTrackSDK\SharedResources\Modules\User\Application\UseCases\RegisterProvider;
use PactTrackSDK\SharedResources\Modules\User\Domain\Exceptions\OAuthAccountNotFoundException;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\OAuthAuthenticationResult;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\OAuthIdentity;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * The use case behind both OAuth entry points on /sign-in and /sign-up —
 * OAuthController is a thin inbound adapter that only translates a Socialite
 * user into an OAuthIdentity and calls this.
 *
 * Matching/creation rule (see the sign-in/sign-up feature's build prompt):
 *
 *   1. An account already linked to this exact external identity
 *      (`users.google_id`/`microsoft_id`) — sign it in. The common case on a
 *      returning visit.
 *   2. No linked account, but an account already exists with this email —
 *      link the identity onto it and sign it in. Handles the person who
 *      signed up with a password (or a different provider) and later clicks
 *      "Continue with Google" using the same address. Google and Microsoft
 *      both only hand back a *verified* email, so trusting the match here is
 *      no weaker than trusting a password-reset email link.
 *   3. No account at all:
 *        - `$intent === 'register'` (came from /sign-up) — create one
 *          through RegisterProvider, the same use case the password sign-up
 *          form uses, so every side effect (default workspace, trial,
 *          `default_workspace_id`) happens in exactly one place.
 *        - otherwise (came from /sign-in) — throw
 *          OAuthAccountNotFoundException. /sign-in never silently creates an
 *          account; the frontend routes this to an inline "sign up instead"
 *          error.
 *
 * Multiple linked providers on one account are allowed, not blocked: Google
 * and Microsoft each get their own column, so a user who first linked Google
 * and later clicks "Continue with Microsoft" with the same email simply gets
 * both linked. The only thing this class refuses to do is silently overwrite
 * an existing, *different* link for the same provider — see
 * linkIdentityIfNeeded() — which should never happen in practice (a verified
 * email cannot belong to two different Google accounts) and is treated as an
 * anomaly to report, not act on.
 */
class AuthenticateViaOAuth
{
    public const INTENT_REGISTER = 'register';

    public const INTENT_LOGIN = 'login';

    public function __construct(
        private readonly UserRepository $users,
        private readonly RegisterProvider $registerProvider,
        private readonly UserAuthentication $authentication,
        private readonly UserHintCookie $hintCookie,
        private readonly NotifySuccessfulSignIn $notifySignIn,
    ) {
    }

    /**
     * @throws OAuthAccountNotFoundException when $intent is not 'register'
     *                                        and no account matches
     */
    public function handle(
        OAuthIdentity $identity,
        string $intent,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): OAuthAuthenticationResult {
        $existing = $this->findLinkedUser($identity)
            ?? $this->users->findByEmail(Str::lower(trim($identity->email)));

        if ($existing !== null) {
            $this->linkIdentityIfNeeded($existing, $identity);

            $this->authentication->login($existing);
            $this->hintCookie->attach($existing);
            // Same audit-log + security-alert treatment as a password
            // sign-in — see NotifySuccessfulSignIn. Deliberately not fired on
            // the fresh-registration branch below, matching
            // RegistrationController::store(), which doesn't fire it either.
            $this->notifySignIn->handle($existing, $ipAddress, $userAgent);

            return OAuthAuthenticationResult::signedIn($existing);
        }

        if ($intent !== self::INTENT_REGISTER) {
            throw new OAuthAccountNotFoundException(
                "No PactTrack account is registered for [{$identity->email}]."
            );
        }

        $provider = $this->registerProvider->handle(
            name: $identity->name,
            email: $identity->email,
            // Never shown to or usable by the user — they authenticate via
            // the linked OAuth identity only. Same "no passwordless-account
            // pattern exists yet" call as everywhere else in this codebase
            // (team/client invitations both collect a real password), so a
            // long random one is generated and hashed like any other.
            password: Str::random(40),
            businessName: $this->deriveBusinessName($identity),
            ownerAttributes: [
                $identity->userColumn() => $identity->externalId,
                // Google/Microsoft only ever hand back a verified address —
                // see OAuthIdentity's own docblock.
                'email_verified_at' => now(),
            ],
        );

        $this->authentication->login($provider->owner);
        $this->hintCookie->attach($provider->owner);

        return OAuthAuthenticationResult::registered($provider->owner);
    }

    private function findLinkedUser(OAuthIdentity $identity): ?User
    {
        return match ($identity->provider) {
            OAuthIdentity::GOOGLE => $this->users->findByGoogleId($identity->externalId),
            OAuthIdentity::MICROSOFT => $this->users->findByMicrosoftId($identity->externalId),
        };
    }

    private function linkIdentityIfNeeded(User $user, OAuthIdentity $identity): void
    {
        $column = $identity->userColumn();
        $current = $user->{$column};

        if ($current === $identity->externalId) {
            return; // Already linked — the common case on a returning visit.
        }

        if ($current !== null) {
            // Anomaly, not a normal branch: this account's stored id for
            // this provider disagrees with the one just presented, which a
            // verified email should make impossible. Leave the existing
            // link alone rather than overwrite it blindly — the email match
            // that got us here is still enough to sign this person in.
            report(new \RuntimeException(
                "OAuth identity mismatch for user #{$user->id}: stored {$identity->provider} id"
                . " does not match the one just presented."
            ));

            return;
        }

        $this->users->saveAttributes($user, [$column => $identity->externalId]);
    }

    private function deriveBusinessName(OAuthIdentity $identity): string
    {
        $base = trim($identity->name) !== '' ? trim($identity->name) : Str::before($identity->email, '@');

        return "{$base}'s Workspace";
    }
}
