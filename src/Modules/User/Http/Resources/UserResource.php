<?php

declare(strict_types=1);

namespace PactTraceSDK\SharedResources\Modules\User\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use PactTraceSDK\SharedResources\Modules\User\Models\User;

/**
 * The authenticated user, as the SPA sees them.
 *
 * Mirrors the `User` interface in frontend/src/context/AuthContext.tsx — keep
 * the two in step. An allow-list rather than the raw model: `$request->user()`
 * would serialise whatever columns exist at the time, so any column added later
 * (an internal flag, a billing id) would silently start appearing in a public
 * response.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'provider_id' => $this->provider_id,
            'role' => $this->primaryRole()?->value,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
        ];
    }
}
