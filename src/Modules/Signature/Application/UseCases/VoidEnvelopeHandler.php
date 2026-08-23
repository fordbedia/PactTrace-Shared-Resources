<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Application\UseCases;

use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * The "Void This Envelope" action on the envelope detail view
 * (/dashboard/signatures/matter/{matterId}) — see .claude/rules/signature.md.
 * `Envelope::markVoided()` already carries the actual state-machine rule
 * (any non-terminal status may transition to voided;
 * EnvelopeCannotTransitionException otherwise, via assertNotTerminal()) —
 * this handler only adds the audit trail entry on top, same shape as
 * Document's ArchiveDocumentHandler/VoidDocumentHandler.
 *
 * Deliberately does not notify signers by email — the artboard's copy
 * implies it, but no "envelope voided" notification exists in the
 * Notification module today and building one is out of scope for wiring
 * this action to a real endpoint. The audit log entry is the durable record
 * of the void; a signer-facing email is a follow-up, not a silent gap in
 * this handler.
 */
class VoidEnvelopeHandler
{
    public function handle(Envelope $envelope, User $actor, ?string $reason = null): Envelope
    {
        $previousStatus = $envelope->status;

        $envelope->markVoided();

        AuditLog::create([
            'provider_id' => $envelope->provider_id,
            'user_id' => $actor->id,
            'action' => 'envelope.voided',
            'auditable_type' => Envelope::class,
            'auditable_id' => $envelope->id,
            'metadata' => array_filter([
                'previous_status' => $previousStatus->value,
                'reason' => $reason,
            ], fn ($value) => $value !== null),
        ]);

        return $envelope;
    }
}
