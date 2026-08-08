<?php

namespace PactTraceSDK\SharedResources\Modules\Messaging\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use PactTraceSDK\SharedResources\Modules\Document\Models\Document;
use PactTraceSDK\SharedResources\Modules\Messaging\Database\Factories\MessageAttachmentFactory;

class MessageAttachment extends Model
{
    use HasFactory;

    protected $fillable = [
        'message_id',
        'document_id',
        'file_name',
        's3_path',
    ];

    protected static function newFactory(): MessageAttachmentFactory
    {
        return MessageAttachmentFactory::new();
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
