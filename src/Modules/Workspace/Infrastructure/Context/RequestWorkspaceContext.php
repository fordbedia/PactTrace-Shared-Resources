<?php

declare(strict_types=1);

namespace PactTraceSDK\SharedResources\Modules\Workspace\Infrastructure\Context;

use App\Models\User;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use PactTraceSDK\SharedResources\Modules\Workspace\Domain\Ports\CurrentWorkspace;
use PactTraceSDK\SharedResources\Modules\Workspace\Models\Workspace;
use Throwable;

/**
 * Resolves the current workspace from the request, then the session, then the
 * authenticated user's provider.
 *
 * Registered as a singleton, so resolution happens at most once per request and
 * every later caller — global scope, Blade directive, use case — sees the same
 * answer.
 *
 * Order of precedence, first hit wins:
 *
 *   1. An explicit setId(), which a workspace switcher or a console command
 *      sets and which nothing else may override.
 *   2. A route parameter named `workspace` (an id, or a bound Workspace).
 *   3. `workspace_id` in the session, which is how the choice survives across
 *      requests once a user has picked one.
 *   4. The authenticated user's provider, *if that provider has exactly one
 *      workspace*. One workspace is the overwhelmingly common case for a solo
 *      professional, and making them pick it on every request would be
 *      pointless ceremony. With two or more it is genuinely ambiguous, so this
 *      resolves to null rather than guessing — guessing here would silently
 *      show a provider the wrong workspace's documents.
 *
 * Steps 2-4 are all confirmed against the actor's own provider before being
 * accepted (see belongsToActor), so a hand-edited route parameter or a stale
 * session value cannot point the context at another tenant's workspace.
 */
final class RequestWorkspaceContext implements CurrentWorkspace
{
    private ?int $workspaceId = null;

    /** Has resolution already run for this request? */
    private bool $resolved = false;

    /** Was the id set explicitly, rather than resolved? */
    private bool $pinned = false;

    /**
     * The container is injected rather than a Request, because this class is a
     * singleton and Laravel rebinds `request` per request — holding the
     * instance handed over at boot would leave a long-running worker resolving
     * the context from the first request it ever served.
     */
    public function __construct(
        private readonly AuthFactory $auth,
        private readonly Container $container,
    ) {
    }

    /**
     * The current request, or null outside an HTTP context.
     */
    private function request(): ?Request
    {
        try {
            $request = $this->container->make('request');
        } catch (Throwable) {
            return null;
        }

        return $request instanceof Request ? $request : null;
    }

    public function id(): ?int
    {
        if ($this->resolved) {
            return $this->workspaceId;
        }

        $this->workspaceId = $this->resolve();
        $this->resolved = true;

        return $this->workspaceId;
    }

    public function setId(?int $workspaceId): void
    {
        $this->workspaceId = $workspaceId;
        $this->resolved = true;
        $this->pinned = true;
    }

    public function forget(): void
    {
        $this->workspaceId = null;
        $this->resolved = false;
        $this->pinned = false;
    }

    public function withoutContext(callable $callback): mixed
    {
        $previousId = $this->workspaceId;
        $previousResolved = $this->resolved;
        $previousPinned = $this->pinned;

        $this->setId(null);

        try {
            return $callback();
        } finally {
            $this->workspaceId = $previousId;
            $this->resolved = $previousResolved;
            $this->pinned = $previousPinned;
        }
    }

    private function resolve(): ?int
    {
        if ($this->pinned) {
            return $this->workspaceId;
        }

        $user = $this->actor();

        if (! $user instanceof User) {
            return null;
        }

        return $this->fromRoute($user)
            ?? $this->fromSession($user)
            ?? $this->soleWorkspaceOf($user);
    }

    /**
     * The authenticated user, or null.
     *
     * Guarded because this runs from a model's global scope, which can fire in
     * contexts with no usable guard at all — a queued job, an early-boot
     * migration, a console command. A failure to identify the actor must mean
     * "no workspace context", never an exception from inside a query.
     */
    private function actor(): ?User
    {
        try {
            $user = $this->auth->guard()->user();
        } catch (Throwable) {
            return null;
        }

        return $user instanceof User ? $user : null;
    }

    private function fromRoute(User $user): ?int
    {
        try {
            $parameter = $this->request()?->route('workspace');
        } catch (Throwable) {
            return null;
        }

        $workspaceId = match (true) {
            $parameter instanceof Workspace => $parameter->getKey(),
            is_numeric($parameter) => (int) $parameter,
            default => null,
        };

        return $this->belongsToActor($workspaceId, $user);
    }

    private function fromSession(User $user): ?int
    {
        try {
            $request = $this->request();

            if ($request === null || ! $request->hasSession()) {
                return null;
            }

            $workspaceId = $request->session()->get('workspace_id');
        } catch (Throwable) {
            return null;
        }

        return $this->belongsToActor(is_numeric($workspaceId) ? (int) $workspaceId : null, $user);
    }

    /**
     * The user's provider's only workspace, or null if they have none or
     * several.
     */
    private function soleWorkspaceOf(User $user): ?int
    {
        if ($user->provider_id === null) {
            return null;
        }

        $workspaceIds = Workspace::query()
            ->where('provider_id', $user->provider_id)
            ->limit(2)
            ->pluck('id');

        return $workspaceIds->count() === 1 ? (int) $workspaceIds->first() : null;
    }

    /**
     * Accept a candidate id only if that workspace belongs to the actor's
     * provider.
     *
     * This is the check that makes a route parameter safe to trust. Without it
     * `/workspaces/{workspace}/documents` would let anyone set the scope to any
     * workspace in the database — turning the isolation scope into a way to
     * cross tenants rather than a way to stay inside one.
     */
    private function belongsToActor(?int $workspaceId, User $user): ?int
    {
        if ($workspaceId === null || $user->provider_id === null) {
            return null;
        }

        $belongs = Workspace::query()
            ->whereKey($workspaceId)
            ->where('provider_id', $user->provider_id)
            ->exists();

        return $belongs ? $workspaceId : null;
    }
}
