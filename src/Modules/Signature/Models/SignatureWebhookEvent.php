<?php

namespace PactTrackSDK\SharedResources\Modules\Signature\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Raw log of every inbound provider webhook, keyed for idempotency by
 * `payload_hash` — see the migration for why, and
 * DocusignWebhookController for how it's used (firstOrCreate on the hash;
 * `wasRecentlyCreated === false` means this exact payload was already
 * processed, so RecordSignatureCompletionUseCase is skipped).
 */
class SignatureWebhookEvent extends Model
{
    protected $fillable = [
        'provider',
        'event_type',
        'provider_envelope_id',
        'envelope_id',
        'payload',
        'payload_hash',
        'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'processed_at' => 'datetime',
    ];

    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class);
    }
}
