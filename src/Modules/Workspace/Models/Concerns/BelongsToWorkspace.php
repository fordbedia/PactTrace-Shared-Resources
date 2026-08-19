<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Workspace\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use PactTrackSDK\SharedResources\Modules\Workspace\Domain\Ports\CurrentWorkspace;
use PactTrackSDK\SharedResources\Modules\Workspace\Models\Scopes\WorkspaceScope;
use PactTrackSDK\SharedResources\Modules\Workspace\Models\Workspace;

/**
 * Applied to every model carrying a denormalised `workspace_id`: Matter,
 * Document and Envelope.
 *
 * Does three things:
 *
 *   1. Registers WorkspaceScope, so reads are confined to the current
 *      workspace without every call site remembering to say so.
 *   2. Fills `workspace_id` on create, from the model's parent where it has one
 *      and from the current context otherwise — so callers do not thread a
 *      workspace id through every factory, seeder and use case.
 *   3. Exposes the escape hatches, because a global scope with no documented
 *      way out gets worked around with raw queries.
 *
 * A model using this trait may override workspaceIdFromParent() to say where
 * its workspace is inherited from.
 */
trait BelongsToWorkspace
{
    public static function bootBelongsToWorkspace(): void
    {
        static::addGlobalScope(new WorkspaceScope());

        static::creating(function (Model $model): void {
            if ($model->getAttribute('workspace_id') !== null) {
                return;
            }

            $model->setAttribute(
                'workspace_id',
                $model->workspaceIdFromParent() ?? app(CurrentWorkspace::class)->id(),
            );
        });
    }

    /**
     * Make `workspace_id` assignable without every model repeating it.
     *
     * The creating hook sets the attribute directly and so does not need this,
     * but callers legitimately pass a workspace explicitly — moving a record,
     * or a seeder building several workspaces in one process — and a guarded
     * attribute would drop that silently.
     */
    public function initializeBelongsToWorkspace(): void
    {
        $this->mergeFillable(['workspace_id']);
    }

    /**
     * The workspace this record inherits from its parent, or null if it has no
     * parent to inherit from.
     *
     * Overridden by Document (from its matter) and Envelope (from its
     * document). Matter has no parent and leaves this as null, falling back to
     * the current context.
     */
    public function workspaceIdFromParent(): ?int
    {
        return null;
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Query without the workspace constraint.
     *
     * For provider-level work that spans workspaces — a billing rollup, an
     * audit export. Everything TenantScopedPolicy enforces still applies; this
     * removes the narrowing, not the tenant boundary.
     */
    public function scopeAcrossWorkspaces(Builder $query): Builder
    {
        return $query->withoutGlobalScope(WorkspaceScope::class);
    }

    /**
     * Query one named workspace, whatever the ambient context is.
     *
     * Unlike the global scope this fails closed: pass null and you get no rows,
     * rather than every row. Use it where a missing workspace should stop the
     * query rather than widen it.
     */
    public function scopeWhereWorkspace(Builder $query, Workspace|int|null $workspace): Builder
    {
        $workspaceId = $workspace instanceof Workspace ? $workspace->getKey() : $workspace;

        return $query
            ->withoutGlobalScope(WorkspaceScope::class)
            ->where(
                $this->qualifyColumn('workspace_id'),
                $workspaceId ?? 0,
            );
    }
}
