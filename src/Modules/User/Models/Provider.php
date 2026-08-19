<?php

namespace PactTrackSDK\SharedResources\Modules\User\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use PactTrackSDK\SharedResources\Modules\Client\Models\Client;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\Document\Models\Folder;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\MessageThread;
use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTrackSDK\SharedResources\Modules\Matter\Models\Matter;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\Modules\User\Database\Factories\ProviderFactory;

class Provider extends Model
{
    use HasFactory;

    protected $fillable = [
        'owner_user_id',
        'business_name',
        'subdomain',
        'custom_domain',
        'logo_path',
        'primary_color',
        'secondary_color',
        'plan',
        'trial_ends_at',
        'docusign_brand_id',
    ];

    protected $casts = [
        'trial_ends_at' => 'datetime',
    ];

    protected static function newFactory(): ProviderFactory
    {
        return ProviderFactory::new();
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'provider_id');
    }

    /**
     * The authoritative billing record. `plan`/`trial_ends_at` on this model
     * are a denormalized cache of this row — see Models\Subscription.
     */
    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
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

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }
}
