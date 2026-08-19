<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Client\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use PactTrackSDK\SharedResources\Modules\Client\Models\Client;

/**
 * A provider's client record.
 *
 * Lives in the Client module (which owns the entity) even though its first
 * consumer is UserResource: a client-role user's `Client` row is the
 * tenant-scoped identity everything they can see hangs off, so the portal needs
 * it alongside the login identity. See .claude/rules/client.md.
 *
 * @mixin Client
 */
class ClientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider_id' => $this->provider_id,
            'user_id' => $this->user_id,
            'name' => $this->name,
            'company_name' => $this->company_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
