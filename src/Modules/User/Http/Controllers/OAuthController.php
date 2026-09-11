<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Auth\AuthenticateViaOAuth;
use PactTrackSDK\SharedResources\Modules\User\Domain\Exceptions\OAuthAccountNotFoundException;
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
     *
     * `?intent=register` (sent only by /sign-up's buttons) is what tells
     * AuthenticateViaOAuth it's allowed to create a brand-new provider
     * account when no match is found; its absence (the /sign-in buttons)
     * means "sign in only, never create."
     */
    public function callback(Request $request, string $provider): RedirectResponse
    {
        $intent = $request->query('intent') === AuthenticateViaOAuth::INTENT_REGISTER
            ? AuthenticateViaOAuth::INTENT_REGISTER
            : AuthenticateViaOAuth::INTENT_LOGIN;
        $originPage = $intent === AuthenticateViaOAuth::INTENT_REGISTER ? '/sign-up' : '/sign-in';

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
            $result = $this->authenticateViaOAuth->handle(
                $identity,
                $intent,
                $request->ip(),
                $request->userAgent(),
            );
        } catch (OAuthAccountNotFoundException $e) {
            Log::info("OAuthController: [{$provider}] sign-in attempted for an unregistered email.");

            return $this->redirectWithError('/sign-in', 'no_account');
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
