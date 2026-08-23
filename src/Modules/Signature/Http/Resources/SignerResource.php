<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A `Signer` row as the envelope detail view's Signer Status card renders
 * it — see .claude/rules/signature.md.
 *
 * @mixin \PactTrackSDK\SharedResources\Modules\Signature\Models\Signer
 */
class SignerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'routing_order' => $this->routing_order,
            'status' => $this->status,
            'signed_at' => $this->signed_at?->toIso8601String(),
            /**
             * Reused as-is from Signer::isGuest() rather than
             * reimplemented — see .claude/rules/signature.md, "Guest
             * signers".
             */
            'is_guest' => $this->isGuest(),
        ];
    }
}
