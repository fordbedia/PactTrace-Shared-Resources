<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Role;
use PactTrackSDK\SharedResources\Modules\User\Models\TeamInvitation;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * One row of /dashboard/team's members table.
 *
 * The list is a merge of two tables — real `users` rows and still-pending
 * `team_invitations` rows. Infrastructure\Service\TeamMemberHandler stamps each
 * item with a `table` discriminator (`'users'` | `'team_invitations'`); this
 * resource echoes it back as `source` so the frontend can tell an active
 * member from an outstanding invite, and normalises both model types into one
 * shape the table renders uniformly.
 *
 * Allow-list on both branches — the invitation `token` is never exposed here,
 * for the same reason TeamInvitationResource omits it.
 *
 * @mixin User|TeamInvitation
 */
class TeamMemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return $this->source() === 'team_invitations'
            ? $this->invitationRow()
            : $this->userRow();
    }

    /**
     * Which table this row came from. Prefers the `table` attribute
     * TeamMemberHandler sets; falls back to the concrete model type so the
     * resource is still correct if used outside that merge.
     */
    private function source(): string
    {
        $table = $this->resource->table ?? null;

        if ($table === 'users' || $table === 'team_invitations') {
            return $table;
        }

        return $this->resource instanceof TeamInvitation ? 'team_invitations' : 'users';
    }

    /**
     * @return array<string, mixed>
     */
    private function userRow(): array
    {
        /** @var User $user */
        $user = $this->resource;

        return [
            'id' => $user->id,
            'source' => 'users',
            'name' => $user->name,
            'email' => $user->email,
            'title' => $user->title,
            'role' => $user->primaryRole()?->value,
            'status' => $user->status,
            'last_active_at' => $user->last_active_at?->toIso8601String(),
            'deactivated_at' => $user->deactivated_at?->toIso8601String(),
            'invited_at' => null,
            'expires_at' => null,
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function invitationRow(): array
    {
        /** @var TeamInvitation $invite */
        $invite = $this->resource;

        $role = $invite->role;

        return [
            'id' => $invite->id,
            'source' => 'team_invitations',
            // An invitee is not a `users` row yet, so there is no name to show.
            'name' => null,
            'email' => $invite->email,
            'title' => $invite->title,
            'role' => $role instanceof Role ? $role->value : $role,
            // Derived, not stored — one of pending / expired / accepted.
            'status' => $invite->isAccepted()
                ? 'accepted'
                : ($invite->isExpired() ? 'expired' : 'pending'),
            'last_active_at' => null,
            'deactivated_at' => null,
            'invited_at' => $invite->created_at?->toIso8601String(),
            'expires_at' => $invite->expires_at?->toIso8601String(),
            'created_at' => $invite->created_at?->toIso8601String(),
        ];
    }
}
