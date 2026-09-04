<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Workspace\Domain\ValueObjects;

/**
 * A snapshot of the live activity `WorkspaceDeactivationPolicy` weighs before
 * letting a provider deactivate a workspace — read for one workspace by the
 * `WorkspaceDeactivationSignalReader` port.
 *
 * Framework-free: plain scalars, no Eloquent. The Infrastructure adapter does
 * the counting (all in SQL); the policy does the deciding. Mirrors the User
 * module's `AccountDeletionSignals`.
 */
final readonly class WorkspaceDeactivationSignals
{
    public function __construct(
        /** Matters in the workspace not yet completed or cancelled. */
        public int $openMatterCount,
        /** Documents in the workspace still out for signature (sent / partially_signed). */
        public int $pendingDocumentCount,
        /** Envelopes in the workspace that have not reached a terminal status. */
        public int $pendingEnvelopeCount,
    ) {
    }
}
