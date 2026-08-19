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
    ];

    protected $casts = [
        'signed_at' => 'datetime',
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
}
