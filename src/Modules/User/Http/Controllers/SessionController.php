<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PactTrackSDK\SharedResources\Modules\User\Application\Services\UserAuthentication;
use PactTrackSDK\SharedResources\Modules\User\Application\Services\UserHintCookie;
use PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Auth\NotifySuccessfulSignIn;
use PactTrackSDK\SharedResources\Modules\User\Http\Resources\UserResource;

/**
 * Sign in and sign out for the SPA.
 *
 * Included alongside registration because AuthContext already calls both
 * (`POST /login`, `POST /logout`); without them the sign-in page and the
 * sign-out button 404 the moment a registered user comes back.
 */
class SessionController extends Controller
{
    public function __construct(
        private readonly UserAuthentication $authentication,
        private readonly UserHintCookie $hintCookie,
        private readonly NotifySuccessfulSignIn $notifySignIn,
    ) {
    }

    /**
     * POST /api/login
     *
     * @throws ValidationException on bad credentials
     */
    public function store(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $signedIn = $this->authentication->attempt(
            $credentials['email'],
            $credentials['password'],
            (bool) ($credentials['remember'] ?? false),
        );

        if (! $signedIn) {
            // Keyed on `email` so the SPA can render it inline, and deliberately
            // vague: saying "no such account" would turn this endpoint into a
            // way to test which email addresses are registered.
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        // Tenancy cross-check (Part 3 of subdomain-based portal host
        // resolution) — `resolved_provider` is only ever set by
        // Http\Middleware\ResolveProviderFromHost for a request that arrived
        // on a TENANT's own subdomain or verified custom domain, never on
        // the platform's own host (see that middleware's docblock) — so this
        // is a pure no-op for every /sign-in login, exactly as before.
        //
        // On a tenant host, the account that just authenticated must belong
        // to THAT tenant. Real threat this closes: Provider A's client
        // entering their own, genuinely correct credentials on Provider B's
        // `/portal/login` (a mistyped/bookmarked/malicious link) would
        // otherwise establish a normal session under Provider B's host with
        // no error at all — see .claude/rules/client.md, "Subdomain-based
        // portal host resolution" for why the browser's own per-host cookie
        // scoping (SESSION_DOMAIN=null) rules out the OTHER failure mode
        // (an already-authenticated session leaking across subdomains).
        //
        // The session this call just started is torn back down completely —
        // never left half-authenticated — and the response is
        // indistinguishable from a wrong password, on purpose: this must
        // never let a caller probe "does this email exist, just on a
        // different tenant" by trying it against every subdomain.
        $resolvedProvider = $request->attributes->get('resolved_provider');

        if ($resolvedProvider !== null && (int) $request->user()->provider_id !== (int) $resolvedProvider->id) {
            $this->authentication->logout();

            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        $this->hintCookie->attach($request->user());

        // Audit + personal "new sign-in" security alert — in the Application
        // layer, not here. See .claude/rules/notification.md.
        $this->notifySignIn->handle(
            $request->user(),
            $request->ip(),
            $request->userAgent() ?: null,
        );

        return response()->json([
            // Same payload GET /api/user returns, so the SPA can seed its auth
            // cache straight from the login response instead of round-tripping.
            // No token is issued: the httpOnly session cookie Laravel just wrote
            // is the credential from here on.
            'user' => new UserResource($request->user()->loadAuthPayload()),
        ]);
    }

    /**
     * POST /api/logout
     *
     * Returns 204 whether or not anyone was signed in — logging out is
     * idempotent, and a browser with a stale cookie asking to be logged out
     * should not be met with an error.
     */
    public function destroy(): JsonResponse
    {
        $this->authentication->logout();
        $this->hintCookie->forget();

        return response()->json(null, 204);
    }
}
