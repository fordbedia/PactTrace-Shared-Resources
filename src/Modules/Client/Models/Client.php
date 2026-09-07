<?php

namespace PactTrackSDK\SharedResources\Modules\Client\Models;

use PactTrackSDK\SharedResources\Modules\User\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use PactTrackSDK\SharedResources\Modules\Client\Database\Factories\ClientFactory;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\Document\Models\Folder;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\MessageThread;
use PactTrackSDK\SharedResources\Modules\Matter\Models\Matter;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\Modules\User\Models\Provider;

/**
 * A Client is a provider-scoped CRM record — NOT workspace-scoped. It
 * deliberately does NOT use BelongsToWorkspace: one client can have matters
 * across several of a provider's workspaces, and the roster on
 * /dashboard/clients must show every client of the tenant regardless of which
 * workspace is active. (It briefly carried the trait + a `clients.workspace_id`
 * column; that silently hid every client with a null workspace_id from any
 * provider that had an active workspace — removed, see
 * 2026_09_07_..._remove_workspace_id_from_clients_table and
 * .claude/rules/client.md.)
 */
class Client extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider_id',
        'user_id',
        'name',
        'company_name',
        'email',
        'phone',
        'status',
    ];

    protected static function newFactory(): ClientFactory
    {
        return ClientFactory::new();
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(ClientInvitation::class);
    }

    public function matters(): HasMany
    {
        return $this->hasMany(Matter::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function folders(): HasMany
    {
        return $this->hasMany(Folder::class);
    }

    public function envelopes(): HasMany
    {
        return $this->hasMany(Envelope::class);
    }

    public function messageThreads(): HasMany
    {
        return $this->hasMany(MessageThread::class);
    }
}
