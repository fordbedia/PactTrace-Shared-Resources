<?php

namespace PactTraceSDK\SharedResources\Modules\Signature\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use PactTraceSDK\SharedResources\Modules\Signature\Database\Factories\SignatureFieldFactory;

class SignatureField extends Model
{
    use HasFactory;

    protected $fillable = [
        'envelope_id',
        'signer_id',
        'field_type',
        'page_number',
        'x_position',
        'y_position',
        'width',
        'height',
    ];

    protected static function newFactory(): SignatureFieldFactory
    {
        return SignatureFieldFactory::new();
    }

    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class);
    }

    public function signer(): BelongsTo
    {
        return $this->belongsTo(Signer::class);
    }
}
