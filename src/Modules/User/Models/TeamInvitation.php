<?php

namespace PactTrackSDK\SharedResources\Modules\User\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PactTrackSDK\SharedResources\Modules\User\Database\Factories\TeamInvitationFactory;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Role;

/**
 * A pending invitation for someone to join a provider as owner or staff.
 *
 * Rich model per CLAUDE.md's hexagonal rule: the "is this link still good?"
 * question lives here (isExpired/isAccepted/isPending) rather than being
 * re-derived in every use case that touches an invitation.
 */
class TeamInvitation extends Model
{
    use HasFactory;

    /** How long a freshly issued (or resent) invitation link stays valid. */
    public const LINK_TTL_DAYS = 7;

    protected $fillable = [
        'provider_id',
        'email',
        'title',
        'role',
        'invited_by_user_id',
        'token',
        'expires_at',
        'accepted_at',
    ];

    protected $casts = [
        // The DB column only allows 'owner'/'staff'; the enum has a third
        // ('client') that an invitation can never carry — see the migration.
        'role' => Role::class,
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    protected static function newFactory(): TeamInvitationFactory
    {
        return TeamInvitationFactory::new();
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    public function isExpired(): bool
    {
        return now()->greaterThan($this->expires_at);
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    /** Neither expired nor already used — the only state in which a link works. */
    public function isPending(): bool
    {
        return ! $this->isExpired() && ! $this->isAccepted();
    }

    /**
     * Why this link can't be redeemed right now, or null if it can.
     *
     * 'accepted' wins over 'expired' when both are true — "you've already used
     * this, go sign in" is the more useful thing to tell someone than "it
     * lapsed". String values (not the Application-layer
     * TeamInvitationNotAcceptableException's consts) so the model stays free of
     * that dependency; the two are kept in step deliberately.
     *
     * @return 'expired'|'accepted'|null
     */
    public function unusableReason(): ?string
    {
        if ($this->isAccepted()) {
            return 'accepted';
        }

        if ($this->isExpired()) {
            return 'expired';
        }

        return null;
    }

    /**
     * A new secret link identifier. 40 chars of `Str::random()` (CSPRNG-backed),
     * the same shape the flow has always used — centralised here so the invite
     * and resend paths can't drift on how a token is minted.
     */
    public static function freshToken(): string
    {
        return Str::random(40);
    }

    /** The expiry to stamp on a token issued right now. */
    public static function defaultExpiry(): Carbon
    {
        return Carbon::now()->addDays(self::LINK_TTL_DAYS);
    }
}
