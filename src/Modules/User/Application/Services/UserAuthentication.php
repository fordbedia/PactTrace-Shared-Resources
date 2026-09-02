<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Application\Services;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;
use PactTrackSDK\SharedResources\Modules\User\Models\User;
use PactTrackSDK\SharedResources\Modules\Workspace\Application\Repository\Ports\WorkspaceRepository;

/**
 * Sign in and sign out — the sibling of UserRegistration in this folder.
 *
 * Session-based, not token-based. The Next.js frontend and this API are served
 * from the same origin by nginx (`/api/` and `/sanctum/` are location blocks on
 * int.pacttrack.com), and frontend/src/lib/api.ts sends `withCredentials: true`
 * after priming `/sanctum/csrf-cookie`. That is Sanctum's SPA mode, where an
 * httpOnly, encrypted session cookie is the credential — it carries only a
 * session id, never the user's name/email/permissions, so nothing sensitive is
 * readable or forgeable from the client side. The user's actual data comes back
 * from `GET /api/user`, which the SPA calls on every load (see AuthContext).
 *
 * The alternative — Sanctum personal access tokens (see Domain/Ports/
 * AccessTokenIssuer) — would mean storing a bearer token in the browser
 * (localStorage or a non-httpOnly cookie), which is strictly worse here: an
 * httpOnly session cookie cannot be read by injected script, and same-origin
 * means none of the cross-domain problems that usually push an SPA toward
 * tokens. Keep that port for a future mobile or public API client, where there
 * is no cookie jar to rely on.
 */
class UserAuthentication
{
    public function __construct(
        private readonly AuthFactory $auth,
        private readonly Session $session,
        private readonly WorkspaceRepository $workspaces,
    ) {
    }

    /**
     * Start a session for a user who has just been created.
     *
     * Used by signup, which has already established who the user is — there is
     * no password to re-check, and asking them to sign in immediately after
     * registering would be theatre.
     *
     * Session fixation is handled: SessionGuard::login() migrates the session id
     * as part of writing the user into it.
     */
    public function login(User $user, bool $remember = false): void
    {
        $this->guard()->login($user, $remember);

        $this->syncWorkspaceSession($user);
    }

    /**
     * Verify credentials and start a session. Returns false on a bad pair
     * rather than throwing — a wrong password is an ordinary outcome of a login
     * form, not an exceptional one.
     */
    public function attempt(string $email, string $password, bool $remember = false): bool
    {
        // Same normalisation rule as UserRegistration::normalizeEmail(); the
        // stored value is lowercased, so the lookup has to be too or nobody who
        // capitalises their email can sign in. Duplicated as one line rather
        // than coupling the two services — extract to a shared value object if
        // a third caller appears.
        $email = Str::lower(trim($email));

        $signedIn = $this->guard()->attempt(
            ['email' => $email, 'password' => $password],
            $remember,
        );

        if ($signedIn) {
            $user = $this->guard()->user();

            if ($user instanceof User) {
                $this->syncWorkspaceSession($user);
            }
        }

        return $signedIn;
    }

    /**
     * End the session completely.
     *
     * All three steps matter: logout() forgets the user, invalidate() drops the
     * session data and its id, and regenerateToken() issues a fresh CSRF token
     * so the next request from this browser is not rejected as a token mismatch.
     */
    public function logout(): void
    {
        $this->guard()->logout();

        $this->session->invalidate();
        $this->session->regenerateToken();
    }

    /**
     * Prime the session's `workspace_id` from the user's stored default, so a
     * multi-workspace provider is dropped back into whatever they were last
     * using instead of nothing (RequestWorkspaceContext's "sole workspace"
     * fallback only ever resolves for a provider with exactly one).
     *
     * Only a live, same-tenant workspace is accepted — a null, stale,
     * cross-tenant or since-deactivated `default_workspace_id` clears the key
     * instead, leaving RequestWorkspaceContext to resolve as it did before this
     * feature. Same rule as RequestWorkspaceContext::belongsToActor(), reached
     * through the WorkspaceRepository port so this class stays free of Eloquent.
     */
    private function syncWorkspaceSession(User $user): void
    {
        $defaultId = $user->default_workspace_id;
        $providerId = $user->provider_id;

        if ($defaultId !== null
            && $providerId !== null
            && $this->workspaces->belongsToProvider((int) $defaultId, (int) $providerId)
        ) {
            $this->session->put('workspace_id', (int) $defaultId);

            return;
        }

        $this->session->forget('workspace_id');
    }

    /**
     * The `web` guard explicitly rather than the default: this class is only
     * ever about the cookie session, and a future change to `auth.defaults.guard`
     * should not silently repoint it.
     */
    private function guard(): StatefulGuard
    {
        /** @var StatefulGuard $guard */
        $guard = $this->auth->guard('web');

        return $guard;
    }
}
