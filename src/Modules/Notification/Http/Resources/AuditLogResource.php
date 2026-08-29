<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Notification\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;

/**
 * One audit-trail row, backing `/dashboard/audit-log` — see
 * .claude/rules/notification.md. Explicit allow-list, same shape as
 * MatterResource.
 *
 * `metadata` is passed through as-is: it is deliberately free-form per action
 * (`previous_status`, `reason`, `plan`, `event_type`, `provider_envelope_id`,
 * …) and the frontend's expandable detail panel renders whatever keys are
 * actually present rather than assuming a fixed shape.
 *
 * `user` is null for a system-initiated row (`user_id` null); `is_system`
 * says so directly so the UI doesn't have to infer it.
 *
 * @mixin AuditLog
 */
class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'is_system' => $this->user_id === null,
            'user' => $this->userPayload(),
            'auditable_type' => $this->auditable_type,
            'auditable_type_label' => $this->auditableTypeLabel(),
            'auditable_id' => $this->auditable_id,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'metadata' => (object) ($this->metadata ?? []),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{id: int, name: string|null, email: string|null, title: string|null}|null
     */
    private function userPayload(): ?array
    {
        if (! $this->relationLoaded('user') || $this->user === null) {
            return null;
        }

        return [
            'id' => $this->user->id,
            'name' => $this->user->name,
            'email' => $this->user->email,
            'title' => $this->user->title,
        ];
    }

    /**
     * The affected record's class basename ("Envelope", "Document",
     * "Subscription") — the "Affected Item" chip shows this plus the id
     * rather than a resolved name, which cannot be done cheaply/generically
     * across every `auditable_type` (see .claude/rules/notification.md).
     */
    private function auditableTypeLabel(): ?string
    {
        if ($this->auditable_type === null) {
            return null;
        }

        $parts = explode('\\', $this->auditable_type);

        return end($parts) ?: $this->auditable_type;
    }
}
