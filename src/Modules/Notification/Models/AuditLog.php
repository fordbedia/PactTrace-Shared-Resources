<?php

namespace PactTrackSDK\SharedResources\Modules\Notification\Models;

use PactTrackSDK\SharedResources\Modules\User\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use PactTrackSDK\SharedResources\Modules\Notification\Database\Factories\AuditLogFactory;
use PactTrackSDK\SharedResources\Modules\User\Models\Provider;
use PactTrackSDK\SharedResources\Modules\Workspace\Models\Concerns\BelongsToWorkspace;

class AuditLog extends Model
{
    use BelongsToWorkspace;
    use HasFactory;

    protected $fillable = [
        'provider_id',
        'user_id',
        'action',
        'auditable_type',
        'auditable_id',
        'ip_address',
        'user_agent',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    protected static function newFactory(): AuditLogFactory
    {
        return AuditLogFactory::new();
    }

    /**
     * An audit row inherits its workspace from the record it documents, when
     * that record carries one. `withoutGlobalScopes()` on the morph lookup so
     * a soft-deleted auditable (e.g. a just-deleted Document) and one scoped to
     * a different workspace than the current context both still resolve.
     *
     * A row whose auditable isn't workspace-scoped — or that has no auditable
     * at all (team / billing / account events) — returns null here and falls
     * back to BelongsToWorkspace's current-context fallback, so it is stamped
     * with whichever workspace the actor was in. That is the same rule Matter
     * uses (no parent → current context), not a second special case.
     */
    public function workspaceIdFromParent(): ?int
    {
        if ($this->auditable_type === null || $this->auditable_id === null) {
            return null;
        }

        $auditable = $this->auditable()->withoutGlobalScopes()->first();

        if ($auditable === null || ! method_exists($auditable, 'workspaceIdFromParent')) {
            return null;
        }

        $workspaceId = $auditable->getAttribute('workspace_id') ?? $auditable->workspaceIdFromParent();

        return $workspaceId === null ? null : (int) $workspaceId;
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }
}
