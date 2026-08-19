<?php

namespace PactTrackSDK\SharedResources\Modules\Messaging\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use PactTrackSDK\SharedResources\Modules\Client\Models\Client;
use PactTrackSDK\SharedResources\Modules\Messaging\Database\Factories\MessageThreadFactory;
use PactTrackSDK\SharedResources\Modules\Matter\Models\Matter;
use PactTrackSDK\SharedResources\Modules\User\Models\Provider;

class MessageThread extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider_id',
        'client_id',
        'matter_id',
        'subject',
        'last_message_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
    ];

    protected static function newFactory(): MessageThreadFactory
    {
        return MessageThreadFactory::new();
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

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'thread_id');
    }
}
