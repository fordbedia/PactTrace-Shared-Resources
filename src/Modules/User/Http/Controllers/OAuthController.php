<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Auth\AuthenticateViaOAuth;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\OAuthAuthenticationResult;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\OAuthIdentity;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Role;
use Throwable;

/**
 * Inbound adapter for "Continue with Google" / "Continue with Microsoft" on
 * /sign-in and /sign-up. Both routes are full-page browser navigations, not
 * XHR — Socialite's redirect can't happen over fetch — and both establish the
 * same Sanctum SPA session SessionController::store() does (Auth::login() on
 * the `web` guard via UserAuthentication), so every existing auth:sanctum
 * route treats an OAuth session identically to a password one.
 *
 * `{provider}` is constrained to `google|microsoft` at the route level (see
 * routes/api.php), so an arbitrary string never reaches Socialite::driver().
 *
 * All real logic — matching/linking/creating the account — lives in
 * AuthenticateViaOAuth; this class only translates Socialite's user object
 * into an OAuthIdentity and turns the outcome into an HTTP redirect. Every
 * failure branch is logged before it redirects away with an `oauth_error`
 * query param, mirroring DocusignWebhookController's rule that a silent
 * early-return must never go unlogged (see .claude/rules/signature.md,
 * "Webhook failures are never silent") — the alternative here is a user
 * staring at a blank tab with no trace of what went wrong.
 */
class OAuthController extends Controller
{
    /**
     * `?intent=register`, sent only by /sign-up's buttons — the ONLY thing
     * this still governs is which of our own pages a failure
     * (`oauth_failed`/`no_email`) bounces back to. AuthenticateViaOAuth no
     * longer branches on it: both /sign-in and /sign-up link-or-create the
     * account the same way (decided by Ed 2026-09-12 — see that class's own
     * docblock for why the earlier /sign-in-never-creates behaviour was
     * dropped).
     */
    private const INTENT_REGISTER = 'register';

    public function __construct(
        private readonly AuthenticateViaOAuth $authenticateViaOAuth,
    ) {
    }

    /**
     * GET /api/auth/{provider}/redirect
     */
    public function redirect(string $provider): RedirectResponse
    {
        return Socialite::driver($provider)->redirect();
    }

    /**
     * GET /api/auth/{provider}/callback
     */
    public function callback(Request $request, string $provider): RedirectResponse
    {
        $originPage = $request->query('intent') === self::INTENT_REGISTER ? '/sign-up' : '/sign-in';

        try {
            $socialiteUser = Socialite::driver($provider)->user();
        } catch (Throwable $e) {
            Log::warning("OAuthController: [{$provider}] Socialite exchange failed.", [
                'exception' => $e->getMessage(),
            ]);

            return $this->redirectWithError($originPage, 'oauth_failed');
        }

        $email = $socialiteUser->getEmail();

        if (! $email) {
            Log::warning("OAuthController: [{$provider}] returned no email address.");

            return $this->redirectWithError($originPage, 'no_email');
        }

        $identity = new OAuthIdentity(
            provider: $provider,
            externalId: (string) $socialiteUser->getId(),
            email: $email,
            name: $socialiteUser->getName() ?: ($socialiteUser->getNickname() ?: $email),
        );

        try {
            $result = $this->authenticateViaOAuth->handle($identity, $request->ip(), $request->userAgent());
        } catch (Throwable $e) {
            report($e);

            return $this->redirectWithError($originPage, 'oauth_failed');
        }

        return redirect()->away($this->destinationUrl($result));
    }

    private function destinationUrl(OAuthAuthenticationResult $result): string
    {
        $base = $this->frontendBase();

        if ($result->created) {
            // Mirrors handleSignUpSubmit's own redirect rule: a brand-new
            // owner still has onboarding step 2 (picking a workspace
            // type/labels) to finish — see .claude/rules/user.md,
            // RegisterProvider, and .claude/rules/workspace.md.
            return $base.'/dashboard/create-workspace?onboarding=1';
        }

        $path = $result->user->primaryRole() === Role::Client ? '/portal' : '/dashboard';

        return $base.$path;
    }

    private function redirectWithError(string $page, string $reason): RedirectResponse
    {
        return redirect()->away($this->frontendBase().$page.'?oauth_error='.urlencode($reason));
    }

    private function frontendBase(): string
    {
        return rtrim((string) config('app.frontend_url'), '/');
    }
}
