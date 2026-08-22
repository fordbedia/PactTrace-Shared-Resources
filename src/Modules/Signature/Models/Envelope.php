<?php

namespace PactTrackSDK\SharedResources\Modules\Signature\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use PactTrackSDK\SharedResources\Modules\Client\Models\Client;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\Signature\Database\Factories\EnvelopeFactory;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Enums\EnvelopeStatus;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Exceptions\EnvelopeCannotTransitionException;
use PactTrackSDK\SharedResources\Modules\User\Models\Provider;
use PactTrackSDK\SharedResources\Modules\Workspace\Models\Concerns\BelongsToWorkspace;

class Envelope extends Model
{
    use BelongsToWorkspace, HasFactory;

    protected $fillable = [
        'provider_id',
        'workspace_id',
        'document_id',
        'client_id',
        'provider',
        'provider_envelope_id',
        'status',
        'sent_at',
        'completed_at',
    ];

    protected $casts = [
        'status' => EnvelopeStatus::class,
        'sent_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected static function newFactory(): EnvelopeFactory
    {
        return EnvelopeFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (self $envelope) {
            $envelope->public_id ??= (string) Str::ulid();
        });
    }

    /**
     * Every client-facing route/URL resolves an Envelope by this
     * non-sequential identifier instead of the auto-increment `id`, which
     * would otherwise be enumerable in a signing link — see
     * .claude/rules/signature.md, "Envelope public identifier". Internal
     * FK relationships (webhook matching, DB joins) are untouched; only
     * route-model binding is affected.
     */
    public function getRouteKeyName(): string
    {
        return 'public_id';
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

    /**
     * The still-editable DocuSign draft for a document, if one exists —
     * the single definition of "resumable" shared by
     * PrepareEnvelopeForSignature::handle() (create-or-reuse) and
     * GetDraftEnvelope (read-only resumability check for the frontend), so
     * the two can never disagree about it. Scoped to `provider = 'docusign'`
     * so a pre-migration Documenso draft row is never mistaken for one — see
     * .claude/rules/signature.md.
     */
    public function scopeReusableDraftFor(Builder $query, int $documentId): Builder
    {
        return $query
            ->where('document_id', $documentId)
            ->where('status', 'draft')
            ->where('provider', 'docusign')
            ->whereNotNull('provider_envelope_id');
    }

    /**
     * Every envelope belonging to a client that hasn't reached a terminal
     * state — the query behind both the client portal's pending-documents
     * list and FindNextPendingSignatureForClientUseCase's chaining. Ordered
     * oldest-sent-first so a client works through their queue in the order
     * things were sent to them, not upload order.
     */
    public function scopePendingForClient(Builder $query, int $clientId): Builder
    {
        return $query
            ->where('client_id', $clientId)
            ->whereNotIn('status', [
                EnvelopeStatus::Completed,
                EnvelopeStatus::Declined,
                EnvelopeStatus::Voided,
                EnvelopeStatus::Expired,
            ])
            ->orderByRaw('sent_at IS NULL, sent_at asc')
            ->orderBy('created_at');
    }

    private function assertNotTerminal(): void
    {
        if ($this->status->isTerminal()) {
            throw EnvelopeCannotTransitionException::terminal($this->status);
        }
    }

    /**
     * The small explicit state machine the feature spec asks for, kept as
     * idempotent forward-only transitions rather than a rigid single-path
     * FSM: webhook delivery order isn't guaranteed, so re-affirming a status
     * the envelope has already passed is a no-op, not an error. Only moving
     * a *terminal* envelope (completed/declined/voided/expired) throws —
     * see assertNotTerminal().
     */
    public function markSent(): void
    {
        $this->assertNotTerminal();

        if ($this->status === EnvelopeStatus::Draft) {
            $this->status = EnvelopeStatus::Sent;
            $this->sent_at ??= now();
            $this->save();
        }
    }

    public function markViewed(): void
    {
        $this->assertNotTerminal();

        if (in_array($this->status, [EnvelopeStatus::Draft, EnvelopeStatus::Sent], true)) {
            $this->status = EnvelopeStatus::Viewed;
            $this->save();
        }
    }

    public function markPartiallySigned(): void
    {
        $this->assertNotTerminal();

        if (in_array($this->status, [EnvelopeStatus::Draft, EnvelopeStatus::Sent, EnvelopeStatus::Viewed], true)) {
            $this->status = EnvelopeStatus::PartiallySigned;
            $this->save();
        }
    }

    public function markCompleted(): void
    {
        $this->assertNotTerminal();

        $this->status = EnvelopeStatus::Completed;
        $this->completed_at ??= now();
        $this->save();
    }

    public function markDeclined(): void
    {
        $this->assertNotTerminal();

        $this->status = EnvelopeStatus::Declined;
        $this->save();
    }

    public function markVoided(): void
    {
        $this->assertNotTerminal();

        $this->status = EnvelopeStatus::Voided;
        $this->save();
    }

    public function markExpired(): void
    {
        $this->assertNotTerminal();

        $this->status = EnvelopeStatus::Expired;
        $this->save();
    }
}
