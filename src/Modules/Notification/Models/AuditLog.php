<?php

namespace PactTrackSDK\SharedResources\Modules\Notification\Models;

use PactTrackSDK\SharedResources\Modules\User\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use PactTrackSDK\SharedResources\Modules\Notification\Database\Factories\AuditLogFactory;
use PactTrackSDK\SharedResources\Modules\User\Models\Provider;

class AuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider_id',
        'user_id',
        'action',
        'auditable_type',
        'auditable_id',
        'ip_address',
        'user_agent',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    protected static function newFactory(): AuditLogFactory
    {
        return AuditLogFactory::new();
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }
}
