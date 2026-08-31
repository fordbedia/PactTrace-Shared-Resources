<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A pending team invitation as the *invitee* sees it, on the unauthenticated
 * accept-invitation page — just enough to render "You've been invited to
 * {provider} as {role}" before they choose a password.
 *
 * Strict allow-list: no `token` (the secret is already in their URL, never
 * echo it back into a page), no ids, nothing about other users or the tenant's
 * internals. Only ever built for an invitation
 * TeamInvitationController::show() has already confirmed is pending.
 *
 * @mixin \PactTrackSDK\SharedResources\Modules\User\Models\TeamInvitation
 */
class PublicTeamInvitationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'email' => $this->email,
            'role' => $this->role->value,
            'title' => $this->title,
            'provider_name' => $this->provider->business_name,
            'expires_at' => $this->expires_at?->toIso8601String(),
        ];
    }
}
