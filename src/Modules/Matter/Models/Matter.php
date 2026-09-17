<?php

namespace PactTrackSDK\SharedResources\Modules\Matter\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use PactTrackSDK\SharedResources\Modules\Client\Models\Client;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\Matter\Domain\Enums\MatterType;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\MessageThread;
use PactTrackSDK\SharedResources\Modules\Matter\Database\Factories\MatterFactory;
use PactTrackSDK\SharedResources\Modules\User\Models\Provider;
use PactTrackSDK\SharedResources\Modules\User\Models\User;
use PactTrackSDK\SharedResources\Modules\Workspace\Models\Concerns\BelongsToWorkspace;

class Matter extends Model
{
    use BelongsToWorkspace, HasFactory;

    protected $fillable = [
        'provider_id',
        'workspace_id',
        'client_id',
        'assigned_staff_id',
        'name',
        'description',
        'status',
        'matter_type',
        'start_date',
        'due_date',
        'archived_at',
    ];

    protected $casts = [
        'start_date' => 'date',
        'due_date' => 'date',
        'archived_at' => 'datetime',
        'matter_type' => MatterType::class,
    ];

    protected static function newFactory(): MatterFactory
    {
        return MatterFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (self $matter) {
            $matter->public_id ??= (string) Str::ulid();
        });

		static::addGlobalScope('exclude_archived', function(Builder $query){
			$query->whereNull('archived_at');
		});
    }

    /**
     * The client portal resolves a matter by this non-sequential identifier
     * rather than the auto-increment `id` — see .claude/rules/matter.md,
     * mirroring Envelope::getRouteKeyName() (.claude/rules/signature.md,
     * "Envelope public identifier"). Provider-side routes bind by it too
     * (there is only one route key per model), which is safe here: nothing
     * on the provider dashboard passes the internal id in a URL today (see
     * MattersController::show()'s docblock — it isn't on the frontend's
     * critical path yet), so this is a behavior-preserving change for every
     * existing caller.
     */
    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * Route-model binding (`{matter}` on every provider-side route —
     * MattersController::show/update/archive/unarchive, and the Signature
     * module's matter-scoped routes) must be able to resolve an archived
     * matter, or two things break: the Matter Detail page 404s the moment a
     * matter is archived (its "matter, milestones, documents and messages
     * remain fully intact and queryable" guarantee — see
     * .claude/rules/matter.md, "Matter Archive / Restore"), and archived
     * matters become permanently stuck — the unarchive endpoint itself binds
     * `{matter}` the same way, so it could never resolve the very record it
     * exists to restore. The `exclude_archived` global scope added in
     * booted() is otherwise correct (it's what keeps an archived matter off
     * `/dashboard/matters` and the stat cards); this only lifts it for the
     * one path — resolving a single record from a URL segment — where
     * archived-ness must never be the reason a lookup fails.
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        return parent::resolveRouteBindingQuery($query->withoutGlobalScope('exclude_archived'), $value, $field);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * The provider-side staff member (or the owner) currently designated as
     * this matter's point of contact — nullable. The provider's owner is
     * always an implicit fallback contact regardless of this value and is
     * never stored here; see Provider::owner() and .claude/rules/matter.md,
     * "Matter-level assigned staff".
     */
    public function assignedStaff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_staff_id');
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(Milestone::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function messageThreads(): HasMany
    {
        return $this->hasMany(MessageThread::class);
    }
}
