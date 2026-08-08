<?php

declare(strict_types=1);

namespace PactTraceSDK\SharedResources\Modules\Workspace\Domain\Ports;

/**
 * The workspace the current actor is working inside.
 *
 * A port so that "which workspace am I in?" has one answer per request, reached
 * the same way from a model's global scope, a Blade directive and a use case —
 * and so tests can set it outright instead of faking a session.
 *
 * The contract is deliberately allowed to answer null. See WorkspaceScope for
 * what null means for querying; the short version is that a null workspace
 * narrows nothing, and tenant isolation continues to rest on `provider_id` and
 * the policies, exactly as it did before workspaces existed.
 */
interface CurrentWorkspace
{
    /**
     * The current workspace id, or null when there is no workspace context.
     *
     * Implementations resolve lazily and cache for the rest of the request:
     * this is called once per query on three models, and must not turn into a
     * lookup per query.
     */
    public function id(): ?int;

    /**
     * Pin the context explicitly. Passing null clears it.
     *
     * Overrides any automatic resolution for the rest of the request — used by
     * a workspace switcher, by console commands that operate on one workspace,
     * and by tests.
     */
    public function setId(?int $workspaceId): void;

    /**
     * Drop the resolved value so the next id() call resolves again from
     * scratch. Needed after a login, an impersonation, or a workspace switch.
     */
    public function forget(): void;

    /**
     * Run a callback with no workspace context at all, restoring the previous
     * one afterwards even if the callback throws.
     *
     * For the genuinely cross-workspace jobs a provider-level admin screen
     * needs — counting every document a provider owns, say.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function withoutContext(callable $callback): mixed;
}
