<?php

namespace PactTraceSDK\SharedResources\Modules\Document\Models;

use PactTraceSDK\SharedResources\Modules\User\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use PactTraceSDK\SharedResources\Modules\Client\Models\Client;
use PactTraceSDK\SharedResources\Modules\Document\Database\Factories\DocumentFactory;
use PactTraceSDK\SharedResources\Modules\Document\Domain\Enums\DocumentStatus;
use PactTraceSDK\SharedResources\Modules\Messaging\Models\MessageAttachment;
use PactTraceSDK\SharedResources\Modules\Matter\Models\Matter;
use PactTraceSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTraceSDK\SharedResources\Modules\User\Models\Provider;
use PactTraceSDK\SharedResources\Modules\Workspace\Models\Concerns\BelongsToWorkspace;

class Document extends Model
{
    use BelongsToWorkspace, HasFactory, SoftDeletes;

    protected $fillable = [
        'provider_id',
        'workspace_id',
        'client_id',
        'matter_id',
        'folder_id',
        'uploaded_by',
        'name',
        's3_path',
        'mime_type',
        'size',
        'version',
        'status',
        'archived_at',
    ];

    protected $casts = [
        'status' => DocumentStatus::class,
        'archived_at' => 'datetime',
    ];

    protected static function newFactory(): DocumentFactory
    {
        return DocumentFactory::new();
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * A document inherits its workspace from the matter it is filed under.
     *
     * Loaded across workspaces because at this point the ambient context may be
     * unset (a queued import) or already narrowed; either way the parent's own
     * workspace is the correct answer, and a scoped lookup could fail to find a
     * matter that plainly exists.
     *
     * A document with no matter — filed straight into the library, or attached
     * to a one-off signature request — returns null here and falls back to the
     * current workspace context.
     */
    public function workspaceIdFromParent(): ?int
    {
        if ($this->matter_id === null) {
            return null;
        }

        $workspaceId = Matter::query()
            ->acrossWorkspaces()
            ->whereKey($this->matter_id)
            ->value('workspace_id');

        return $workspaceId === null ? null : (int) $workspaceId;
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class);
    }

    public function envelopes(): HasMany
    {
        return $this->hasMany(Envelope::class);
    }

    public function messageAttachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class);
    }
}
