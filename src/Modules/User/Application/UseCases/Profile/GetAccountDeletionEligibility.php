<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Profile;

use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\AccountDeletionSignalReader;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\AccountDeletionSignals;

/**
 * Backs `GET /api/v1/profile/deletion-eligibility` — the pre-flight the
 * `/profile` delete modal runs before it renders the name/password form.
 *
 * Returns the raw signal snapshot; the controller applies
 * `AccountDeletionPolicy` to turn it into the blocker list on the wire. That
 * split keeps the policy the single decision-maker (the same call is made
 * again inside `DeleteOwnAccount`).
 */
final class GetAccountDeletionEligibility
{
    public function __construct(
        private readonly AccountDeletionSignalReader $reader,
    ) {
    }

    public function handle(int $providerId): AccountDeletionSignals
    {
        return $this->reader->read($providerId);
    }
}
