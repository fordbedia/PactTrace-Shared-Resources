<?php

namespace PactTrackSDK\SharedResources\Modules\User\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use PactTrackSDK\SharedResources\Modules\Client\Models\Client;
use PactTrackSDK\SharedResources\Modules\User\Database\Factories\UserFactory;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Role;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'title', 'password', 'provider_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    /**
     * Everything UserResource serialises beyond the `users` row itself.
     *
     * `roles.permissions` and `permissions` are both needed: spatie's
     * getAllPermissions() unions the role-derived set with any direct grants,
     * and queries for whichever half is not already in memory.
     *
     * @var list<string>
     */
    public const AUTH_PAYLOAD_RELATIONS = [
        'provider.subscription',
        'client',
        'roles.permissions',
        'permissions',
    ];

    /**
     * Eager-load the relations UserResource emits, for an instance already in
     * hand (typically `$request->user()`).
     *
     * loadMissing() rather than load() so calling it twice in one request — say
     * a controller that serialises the actor after acting on them — does not
     * re-run the queries.
     */
    public function loadAuthPayload(): static
    {
        return $this->loadMissing(self::AUTH_PAYLOAD_RELATIONS);
    }

    /**
     * The same set, applied to a query that has not run yet.
     */
    public function scopeWithAuthPayload(Builder $query): Builder
    {
        return $query->with(self::AUTH_PAYLOAD_RELATIONS);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function ownedProvider(): HasOne
    {
        return $this->hasOne(Provider::class, 'owner_user_id');
    }

    public function client(): HasOne
    {
        return $this->hasOne(Client::class);
    }

    /**
     * The user's PactTrack role as a domain value object.
     *
     * A user is expected to hold exactly one of owner/staff/client; if several
     * are somehow assigned, the most privileged wins so authorisation never
     * silently downgrades. Returns null for a user with no role yet (e.g. mid
     * invitation flow), which every policy must treat as "denied".
     *
     * Named `primaryRole` rather than `role` to stay clear of spatie's `role`
     * query scope.
     */
    public function primaryRole(): ?Role
    {
        $names = $this->getRoleNames();

        foreach (Role::cases() as $role) {
            if ($names->contains($role->value)) {
                return $role;
            }
        }

        return null;
    }

    /**
     * Does this user act on behalf of the provider (owner or staff), rather
     * than on behalf of one of the provider's clients?
     */
    public function isProviderSide(): bool
    {
        return $this->primaryRole()?->isProviderSide() ?? false;
    }

    public function isClientUser(): bool
    {
        return $this->primaryRole() === Role::Client;
    }
}
