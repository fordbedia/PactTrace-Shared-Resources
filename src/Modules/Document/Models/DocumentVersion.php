<?php

namespace PactTraceSDK\SharedResources\Modules\Document\Models;

use PactTraceSDK\SharedResources\Modules\User\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use PactTraceSDK\SharedResources\Modules\Document\Database\Factories\DocumentVersionFactory;

class DocumentVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'document_id',
        'uploaded_by',
        's3_path',
        'version',
        'size',
    ];

    protected static function newFactory(): DocumentVersionFactory
    {
        return DocumentVersionFactory::new();
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
