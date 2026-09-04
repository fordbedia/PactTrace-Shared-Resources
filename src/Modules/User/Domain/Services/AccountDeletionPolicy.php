<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Domain\Services;

use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\AccountDeletionBlocker;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\AccountDeletionSignals;

/**
 * Decides whether a user may delete their own account, given a snapshot of
 * their provider's outstanding commitments.
 *
 * Pure domain logic — no I/O. The `/profile` deletion flow calls
 * `blockers()` twice: once for the modal's pre-flight check (before it asks
 * for name/password), and again inside `DeleteOwnAccount` right before the
 * soft-delete, so a blocker that appeared in between still stops it.
 *
 * "Active subscription" means a live, converted paid plan (`status: active`)
 * — a trial, past-due, cancelled or expired subscription is not something the
 * user needs to "unsubscribe" from first, so those never block.
 */
final class AccountDeletionPolicy
{
    /** @var list<string> */
    private const BLOCKING_SUBSCRIPTION_STATUSES = ['active'];

    /**
     * Every reason this account can't be deleted right now, in display order.
     * An empty list means deletion is permitted.
     *
     * @return list<AccountDeletionBlocker>
     */
    public static function blockers(AccountDeletionSignals $signals): array
    {
        $blockers = [];

        if (
            $signals->subscriptionStatus !== null
            && in_array($signals->subscriptionStatus, self::BLOCKING_SUBSCRIPTION_STATUSES, true)
        ) {
            $blockers[] = AccountDeletionBlocker::ActiveSubscription;
        }

        if ($signals->pendingDocumentCount > 0) {
            $blockers[] = AccountDeletionBlocker::PendingDocuments;
        }

        // Unaccepted team/client invitations are not blockers — deletion
        // expires them as a side effect instead. See DeleteOwnAccount.

        return $blockers;
    }

    public static function permitsDeletion(AccountDeletionSignals $signals): bool
    {
        return self::blockers($signals) === [];
    }
}
