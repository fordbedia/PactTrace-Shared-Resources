<?php

namespace PactTraceSDK\SharedResources\Modules\Signature\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use PactTraceSDK\SharedResources\Modules\Client\Models\Client;
use PactTraceSDK\SharedResources\Modules\Document\Models\Document;
use PactTraceSDK\SharedResources\Modules\Signature\Database\Factories\EnvelopeFactory;
use PactTraceSDK\SharedResources\Modules\User\Models\Provider;
use PactTraceSDK\SharedResources\Modules\Workspace\Models\Concerns\BelongsToWorkspace;

class Envelope extends Model
{
    use BelongsToWorkspace, HasFactory;

    protected $fillable = [
        'provider_id',
        'workspace_id',
        'document_id',
        'client_id',
        'provider_envelope_id',
        'status',
        'sent_at',
        'completed_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected static function newFactory(): EnvelopeFactory
    {
        return EnvelopeFactory::new();
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * An envelope inherits its workspace from the document being signed.
     *
     * `document_id` is not nullable on this table — there is no envelope
     * without a document — so this only returns null if the document itself was
     * never assigned a workspace, in which case the current context applies.
     */
    public function workspaceIdFromParent(): ?int
    {
        $workspaceId = Document::query()
            ->acrossWorkspaces()
            ->whereKey($this->document_id)
            ->value('workspace_id');

        return $workspaceId === null ? null : (int) $workspaceId;
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function signers(): HasMany
    {
        return $this->hasMany(Signer::class);
    }

    public function fields(): HasMany
    {
        return $this->hasMany(SignatureField::class);
    }
}
