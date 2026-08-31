<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use PactTrackSDK\SharedResources\Modules\User\Models\TeamInvitation;

/**
 * A pending team invitation, as staff on /dashboard/team see it.
 *
 * Allow-list — and `token` is deliberately never on it. The secret link
 * identifier must not appear in a response a staff member can read in browser
 * history / devtools; it only ever travels in the accept-invitation email.
 *
 * @mixin TeamInvitation
 */
class TeamInvitationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'title' => $this->title,
            'role' => $this->role->value,
            // Derived, not stored — one of pending / expired / accepted.
            'status' => $this->isAccepted() ? 'accepted' : ($this->isExpired() ? 'expired' : 'pending'),
            'invited_by_user_id' => $this->invited_by_user_id,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
