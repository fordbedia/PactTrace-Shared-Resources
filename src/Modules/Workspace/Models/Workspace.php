<?php

declare(strict_types=1);

namespace PactTraceSDK\SharedResources\Modules\Workspace\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use PactTraceSDK\SharedResources\Modules\Document\Models\Document;
use PactTraceSDK\SharedResources\Modules\Matter\Models\Matter;
use PactTraceSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTraceSDK\SharedResources\Modules\User\Models\Provider;
use PactTraceSDK\SharedResources\Modules\Workspace\Database\Factories\WorkspaceFactory;
use PactTraceSDK\SharedResources\Modules\Workspace\Domain\Ports\WorkspacePresets;
use PactTraceSDK\SharedResources\Modules\Workspace\Domain\ValueObjects\WorkspaceLabels;
use PactTraceSDK\SharedResources\Modules\Workspace\Domain\ValueObjects\WorkspaceType;

/**
 * A provider's branded space and the vocabulary it presents to clients.
 *
 * Sits inside a Provider rather than replacing it: `provider_id` is still the
 * tenant boundary, and a workspace narrows within it. See the create migration
 * for why.
 *
 * The two label columns are filled from the type's preset when a workspace is
 * created and are freely editable afterwards. Reading them back always goes
 * through labels(), which falls back to the preset for anything blank, so a
 * screen never has to handle an empty noun.
 */
class Workspace extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'provider_id',
        'owner_id',
        'name',
        'workspace_type',
        'client_label',
        'engagement_label',
    ];

    protected $casts = [
        'workspace_type' => WorkspaceType::class,
    ];

    protected static function newFactory(): WorkspaceFactory
    {
        return WorkspaceFactory::new();
    }

    /**
     * Fill any label the caller left blank from the chosen type's preset.
     *
     * On creating rather than in a use case because a workspace with an empty
     * label is not a valid workspace at all, and factories, seeders and future
     * controllers would each have to remember to apply the preset otherwise.
     * Overrides always win: a caller who supplies a label keeps it, which is
     * what makes "pick a preset, then rename it" work.
     */
    protected static function booted(): void
    {
        static::creating(function (self $workspace): void {
            $type = $workspace->workspace_type instanceof WorkspaceType
                ? $workspace->workspace_type
                : WorkspaceType::tryFrom((string) $workspace->workspace_type) ?? WorkspaceType::default();

            $workspace->workspace_type = $type;

            $preset = app(WorkspacePresets::class)->for($type);

            $workspace->client_label = self::blankToNull($workspace->client_label) ?? $preset->client;
            $workspace->engagement_label = self::blankToNull($workspace->engagement_label) ?? $preset->engagement;
        });
    }

    /**
     * This workspace's labels, with the type's preset covering anything blank.
     *
     * The single read path for wording — workspace_label() and the
     * @workspaceLabel directive both end up here.
     */
    public function labels(): WorkspaceLabels
    {
        $type = $this->workspace_type instanceof WorkspaceType
            ? $this->workspace_type
            : WorkspaceType::default();

        $preset = app(WorkspacePresets::class)->for($type);

        return $preset->override(
            self::blankToNull($this->client_label),
            self::blankToNull($this->engagement_label),
        );
    }

    /**
     * Reset both labels to the current preset for a type, discarding overrides.
     *
     * The "start over" action a settings screen needs after a provider has
     * renamed things and wants the defaults back.
     */
    public function applyPreset(WorkspaceType $type): self
    {
        $preset = app(WorkspacePresets::class)->for($type);

        $this->forceFill([
            'workspace_type' => $type,
            'client_label' => $preset->client,
            'engagement_label' => $preset->engagement,
        ]);

        return $this;
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * The children below all drop WorkspaceScope.
     *
     * Reading `$workspace->documents` already names the workspace — the foreign
     * key is the constraint. Leaving the global scope on would AND it with
     * whatever workspace happens to be current, so asking a workspace for its
     * own documents from inside a different one would return nothing at all
     * rather than the rows plainly asked for. The scope exists to narrow
     * unqualified queries, and these are qualified.
     */
    public function matters(): HasMany
    {
        return $this->hasMany(Matter::class)->acrossWorkspaces();
    }

    /**
     * Documents belong to a matter, but are also reachable straight from the
     * workspace — that is the whole reason `workspace_id` is denormalised onto
     * them. A document filed outside any matter is still in the workspace.
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class)->acrossWorkspaces();
    }

    public function envelopes(): HasMany
    {
        return $this->hasMany(Envelope::class)->acrossWorkspaces();
    }

    private static function blankToNull(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }
}
