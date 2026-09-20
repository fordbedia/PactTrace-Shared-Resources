<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Domain\ValueObjects;

/**
 * One signer as the e-signature provider currently reports it on an
 * envelope — the provider is the source of truth for recipients while the
 * envelope is still a draft (a tenant can add/remove/edit signers inside
 * the provider's own Sender View without PactTrack being told). Provider
 * status is already normalized to Signer::status vocabulary
 * (pending/sent/viewed/signed/declined) by the adapter.
 */
final class ProviderRecipient
{
    public function __construct(
        public readonly string $recipientId,
        public readonly string $name,
        public readonly string $email,
        public readonly string $status,
        public readonly ?string $clientUserId,
        public readonly int $routingOrder = 1,
    ) {
    }
}
