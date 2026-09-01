<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Infrastructure\Repositories\Eloquent;

use PactTrackSDK\SharedResources\Modules\Client\Models\ClientInvitation;
use PactTrackSDK\SharedResources\Modules\Document\Domain\Enums\DocumentStatus;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\AccountDeletionSignalReader;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\AccountDeletionSignals;
use PactTrackSDK\SharedResources\Modules\User\Models\Subscription;
use PactTrackSDK\SharedResources\Modules\User\Models\TeamInvitation;
use PactTrackSDK\SharedResources\Modules\Workspace\Models\Scopes\WorkspaceScope;

/**
 * Counts, in SQL, the things that block self-deletion for one provider.
 *
 * The Document count deliberately drops the `WorkspaceScope` global scope: the
 * question is "does this provider have ANY document out for signature", across
 * every workspace, and the acting request may carry a narrower workspace
 * context that would under-count. SoftDeletes' scope is left on, so trashed
 * documents don't count.
 */
final class EloquentAccountDeletionSignals implements AccountDeletionSignalReader
{
    public function read(int $providerId): AccountDeletionSignals
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

        $pendingTeamInvitations = TeamInvitation::query()
            ->where('provider_id', $providerId)
            ->whereNull('accepted_at')
            ->count();

        $pendingClientInvitations = ClientInvitation::query()
            ->where('provider_id', $providerId)
            ->whereNull('accepted_at')
            ->count();

        return new AccountDeletionSignals(
            $subscriptionStatus !== null ? (string) $subscriptionStatus : null,
            $pendingDocuments,
            $pendingTeamInvitations,
            $pendingClientInvitations,
        );
    }
}
