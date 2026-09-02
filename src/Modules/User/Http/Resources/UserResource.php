<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use PactTrackSDK\SharedResources\Modules\Client\Http\Resources\ClientResource;
use PactTrackSDK\SharedResources\Modules\User\Domain\Ports\AvatarStorage;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * The authenticated user, as the SPA sees them.
 *
 * Mirrors the `User` interface in frontend/src/context/AuthContext.tsx — keep
 * the two in step. An allow-list rather than the raw model: `$request->user()`
 * would serialise whatever columns exist at the time, so any column added later
 * (an internal flag, a billing id) would silently start appearing in a public
 * response. `password` and `remember_token` are excluded by construction here,
 * not merely by the model's #[Hidden] attribute.
 *
 * Relationships are emitted with `whenLoaded`, so this resource never triggers
 * a query of its own — a caller that wants them must eager-load them first with
 * `User::loadAuthPayload()` (or the `withAuthPayload()` query scope). Miss that
 * and the keys are silently absent rather than lazy-loaded, which is the
 * trade-off taken on purpose: a missing key in dev is cheaper to notice than an
 * N+1 in production.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            // ── identity ────────────────────────────────────────────────
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            // Free-text job title, shown in the portal staff directory.
            // Display only — unrelated to role/permissions.
            'title' => $this->title,
            // Free-text contact number, edited on `/profile`. Display/contact
            // only — nothing functional depends on it.
            'phone' => $this->phone,
            // The public URL of the uploaded profile photo, or null when the
            // user has none (the SPA renders initials then). The raw
            // `avatar_path` is never on the wire — resolving a storage key to a
            // URL is an Infrastructure concern. `url()` is pure string-building
            // for the local/public disk, so this stays query-free like the rest
            // of the resource.
            'avatar_url' => $this->avatar_path !== null
                ? app(AvatarStorage::class)->url($this->avatar_path)
                : null,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            // ── tenancy + authorisation ─────────────────────────────────
            'provider_id' => $this->provider_id,
            // The workspace the SPA treats as "active" — kept in lockstep with
            // the server session's `workspace_id` (set at login, rewritten on
            // every switch). Just the id; the switcher reads the rest from
            // `GET /api/v1/workspaces`. May be null, or point at a workspace
            // that has since been deactivated — the switcher just won't mark a
            // row active in that case.
            'default_workspace_id' => $this->default_workspace_id,
            // Kept as the single scalar the SPA branches on. `roles` below is
            // the raw spatie assignment; `role` is the domain answer (most
            // privileged wins), so the two can differ if a user somehow holds
            // several — see User::primaryRole().
            'role' => $this->primaryRole()?->value,
            'roles' => $this->whenLoaded(
                'roles',
                fn () => $this->roles->pluck('name')->values()->all(),
            ),
            // Role-derived plus any direct grants, flattened — enough for the
            // SPA to hide actions it would be denied anyway. It is a UI hint,
            // never the authorisation itself: every request still goes through
            // TenantScopedPolicy server-side.
            'permissions' => $this->when(
                $this->relationLoaded('roles') && $this->relationLoaded('permissions'),
                fn () => $this->getAllPermissions()->pluck('name')->sort()->values()->all(),
            ),
            'is_provider_side' => $this->isProviderSide(),
            'is_client_user' => $this->isClientUser(),

            // ── relationships ───────────────────────────────────────────
            // The tenant this user acts inside (staff and owners alike).
            //
            // Guarded on non-null rather than written as
            // `ProviderResource::make($this->whenLoaded('provider'))`: an
            // eager-loaded relation that resolved to nothing is still "loaded",
            // so that idiom emits a resource wrapping null — an object with
            // every key set to null — instead of omitting the key.
            'provider' => $this->when(
                $this->relationLoaded('provider') && $this->provider !== null,
                fn () => ProviderResource::make($this->provider),
            ),
            // True when this user is the account owner rather than staff.
            // Derived from the already-loaded provider instead of eager-loading
            // `ownedProvider`, which would re-fetch the same row.
            'owns_provider' => $this->when(
                $this->relationLoaded('provider'),
                fn () => $this->provider?->owner_user_id === $this->id,
            ),
            // Present only for a client-role user who has been linked to a
            // `clients` row; until that link exists every tenant-scoped policy
            // denies them, so its absence is meaningful to the portal.
            'client' => $this->when(
                $this->relationLoaded('client') && $this->client !== null,
                fn () => ClientResource::make($this->client),
            ),
        ];
    }
}
