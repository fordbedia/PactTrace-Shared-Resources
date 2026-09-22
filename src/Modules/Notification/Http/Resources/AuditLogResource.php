<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Notification\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use PactTrackSDK\SharedResources\Modules\Notification\Domain\ValueObjects\ActorType;
use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Role;

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
 * says so directly so the UI doesn't have to infer it. `is_system` is now
 * derived from `actor_label` rather than the bare `user_id === null` check —
 * a `guest_signer` row also has `user_id === null` but must never render as
 * "System".
 *
 * `actor_type` / `actor_label` / `actor_name` / `actor_email` make the actor
 * explicit for every row (Staff / Client / Guest Signer / System) — see the
 * "Audit Log: Explicit Client & Guest Signer Activity" build. Computed here,
 * once, so the frontend never re-derives role logic itself:
 *
 *   - `actor_type === 'guest_signer'` → 'Guest Signer', name/email from
 *     `metadata.signer_name` / `metadata.signer_email` (a guest has no
 *     `users` row — see Signer::isGuest()).
 *   - `actor_type === 'user'` (or legacy rows with no `actor_type` at all,
 *     which predate this column but still carry a real `user`) → 'Staff'
 *     when the user's role is owner/admin/staff, 'Client' when it's
 *     `client`; name/email from the eager-loaded `user`.
 *   - anything else (`actor_type === 'system'`, or no user at all) →
 *     'System'.
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
            'is_system' => $this->actorLabel() === 'System',
            'user' => $this->userPayload(),
            'actor_type' => $this->actor_type,
            'actor_label' => $this->actorLabel(),
            'actor_name' => $this->actorName(),
            'actor_email' => $this->actorEmail(),
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

    private function isGuestSignerRow(): bool
    {
        return $this->actor_type === ActorType::GuestSigner->value;
    }

    private function hasLoadedUser(): bool
    {
        return $this->relationLoaded('user') && $this->user !== null;
    }

    /**
     * 'Staff' vs 'Client' when the actor is a real `users` row — never
     * re-derived on the frontend. Falls back to 'Staff' for a `user` row
     * whose role can't be resolved (shouldn't happen, but never silently
     * mislabel a real actor as System).
     */
    private function actorLabel(): string
    {
        if ($this->isGuestSignerRow()) {
            return 'Guest Signer';
        }

        if ($this->hasLoadedUser()) {
            $role = $this->user->primaryRole();

            return $role === Role::Client ? 'Client' : 'Staff';
        }

        return 'System';
    }

    private function actorName(): ?string
    {
        if ($this->isGuestSignerRow()) {
            return $this->metadata['signer_name'] ?? null;
        }

        return $this->hasLoadedUser() ? $this->user->name : null;
    }

    private function actorEmail(): ?string
    {
        if ($this->isGuestSignerRow()) {
            return $this->metadata['signer_email'] ?? null;
        }

        return $this->hasLoadedUser() ? $this->user->email : null;
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
