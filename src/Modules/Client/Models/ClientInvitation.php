<?php

namespace PactTraceSDK\SharedResources\Modules\Client\Models;

use PactTraceSDK\SharedResources\Modules\User\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use PactTraceSDK\SharedResources\Modules\Client\Database\Factories\ClientInvitationFactory;
use PactTraceSDK\SharedResources\Modules\User\Models\Provider;

class ClientInvitation extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider_id',
        'client_id',
        'invited_by',
        'email',
        'token',
        'expires_at',
        'accepted_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    protected static function newFactory(): ClientInvitationFactory
    {
        return ClientInvitationFactory::new();
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
