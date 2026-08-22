<?php

namespace PactTrackSDK\SharedResources\Modules\Signature\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use PactTrackSDK\SharedResources\Modules\Signature\Database\Factories\SignerFactory;

class Signer extends Model
{
    use HasFactory;

    protected $fillable = [
        'envelope_id',
        'provider_signer_id',
        'name',
        'email',
        'routing_order',
        'status',
        'signed_at',
        'signing_token_hash',
        'signing_token_expires_at',
        'signing_token_consumed_at',
    ];

    protected $hidden = [
        'signing_token_hash',
    ];

    protected $casts = [
        'signed_at' => 'datetime',
        'signing_token_expires_at' => 'datetime',
        'signing_token_consumed_at' => 'datetime',
    ];

    protected static function newFactory(): SignerFactory
    {
        return SignerFactory::new();
    }

    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class);
    }

    public function fields(): HasMany
    {
        return $this->hasMany(SignatureField::class);
    }

    /**
     * A guest (no PactTrack account) signer is any signer that was ever
     * issued a guest signing token — see GuestSigningTokenService. The
     * primary, portal-authenticated client signer never has one, so this
     * is a sufficient signal on its own; no separate boolean column.
     */
    public function isGuest(): bool
    {
        return $this->signing_token_hash !== null;
    }
}
