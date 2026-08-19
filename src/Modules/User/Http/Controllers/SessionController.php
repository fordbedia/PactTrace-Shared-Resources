<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PactTrackSDK\SharedResources\Modules\User\Application\Services\UserAuthentication;
use PactTrackSDK\SharedResources\Modules\User\Application\Services\UserHintCookie;
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

        $this->hintCookie->attach($request->user());

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
