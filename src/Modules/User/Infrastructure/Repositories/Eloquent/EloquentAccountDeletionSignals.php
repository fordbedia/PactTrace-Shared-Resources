<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Infrastructure\Repositories\Eloquent;

use PactTrackSDK\SharedResources\Modules\Document\Domain\Enums\DocumentStatus;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\AccountDeletionSignalReader;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\AccountDeletionSignals;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Role;
use PactTrackSDK\SharedResources\Modules\User\Models\Subscription;
use PactTrackSDK\SharedResources\Modules\User\Models\User;
use PactTrackSDK\SharedResources\Modules\Workspace\Models\Scopes\WorkspaceScope;

/**
 * Counts, in SQL, the things that block self-deletion for one provider, plus
 * the informational active-staff count.
 *
 * The Document count deliberately drops the `WorkspaceScope` global scope: the
 * question is "does this provider have ANY document out for signature", across
 * every workspace, and the acting request may carry a narrower workspace
 * context that would under-count. SoftDeletes' scope is left on, so trashed
 * documents don't count.
 *
 * Unaccepted team/client invitations are no longer read here — they stopped
 * being blockers; `ProviderInvitationCanceller` expires them as a side effect
 * of the deletion instead.
 */
final class EloquentAccountDeletionSignals implements AccountDeletionSignalReader
{
    public function read(int $providerId, ?int $excludeUserId = null): AccountDeletionSignals
    {
        $subscriptionStatus = Subscription::query()
            ->where('provider_id', $providerId)
            ->value('status');

        $pendingDocuments = Document::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->where('provider_id', $providerId)
            ->whereIn('status', [
                DocumentStatus::Sent->value,
                DocumentStatus::PartiallySigned->value,
            ])
            ->count();

        $activeStaff = User::query()
            ->where('provider_id', $providerId)
            ->where('status', 'active')
            ->when($excludeUserId !== null, fn ($q) => $q->where('id', '!=', $excludeUserId))
            ->whereHas('roles', fn ($q) => $q->whereIn('name', array_map(
                static fn (Role $role): string => $role->value,
                Role::providerSide(),
            )))
            ->count();

        return new AccountDeletionSignals(
            $subscriptionStatus !== null ? (string) $subscriptionStatus : null,
            $pendingDocuments,
            $activeStaff,
        );
    }
}
