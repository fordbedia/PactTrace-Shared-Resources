<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Workspace\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use PactTrackSDK\SharedResources\Modules\Workspace\Domain\Ports\CurrentWorkspace;

/**
 * Constrains every query on a workspace-scoped model to the current workspace.
 *
 * ## What happens when there is no workspace context
 *
 * The scope adds nothing. That is a deliberate choice and the one thing to
 * understand before relying on this class.
 *
 * The alternative — returning no rows without a context — sounds safer and is
 * not. `provider_id` is still the tenant boundary here, still checked by
 * TenantScopedPolicy on every authorisation, and workspaces narrow *within* a
 * provider. So an unset context cannot leak across tenants; it can only fail to
 * narrow within one. Meanwhile a fail-closed default would make every queued
 * job, console command and migration silently see an empty database, and those
 * run with no authenticated user by definition. Bugs of that shape are found in
 * production, at the point where a notification quietly stops being sent.
 *
 * Requests are the case that matters, and requests do have a context:
 * RequestWorkspaceContext resolves one from the route, the session or the
 * provider's sole workspace, and validates it against the actor's provider
 * before accepting it.
 *
 * If you want a query to fail closed regardless, ask for it explicitly with
 * `Model::query()->whereWorkspace($id)`.
 */
final class WorkspaceScope implements Scope
{
    /**
     * The scope's identifier, used by withoutGlobalScope() calls that want to
     * name it as a string rather than importing the class.
     */
    public const IDENTIFIER = 'workspace';

    public function apply(Builder $builder, Model $model): void
    {
        $workspaceId = app(CurrentWorkspace::class)->id();

        if ($workspaceId === null) {
            return;
        }

        // Qualified with the table name so the constraint survives a join
        // against another table that also has a workspace_id — which, since
        // the column is denormalised onto three tables, is not hypothetical.
        $builder->where($model->qualifyColumn('workspace_id'), $workspaceId);
    }
}
