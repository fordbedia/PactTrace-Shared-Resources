<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Domain\ValueObjects;

/**
 * A DocuSign Connect webhook payload, normalized into the handful of facts
 * RecordSignatureCompletionUseCase actually needs. Keeping this mapping on
 * the value object (rather than inside DocusignWebhookController or the
 * adapter) means it's pure data-shaping, testable without an HTTP client or
 * a container.
 *
 * `eventType` is driven off `data.envelopeSummary.status` — DocuSign's
 * *current* envelope status string (sent/delivered/completed/declined/
 * voided), present on every Connect delivery regardless of which underlying
 * DocuSign event fired — rather than the top-level `event` field
 * ("envelope-sent" etc.), because the status is what RecordSignatureCompletionUseCase
 * actually needs to drive the Envelope state machine and is more robust to
 * exactly which Connect events a given configuration has enabled.
 *
 * `recipientEmail` is best-effort: the email of the signer with the most
 * recent `signedDateTime` among any 'completed' recipients on the payload —
 * used only to keep the local `Signer` row's status/email in sync when the
 * envelope reaches `completed`. PactTrack's product currently sends to
 * exactly one recipient per envelope (see EnvelopeRecipient), so this never
 * needs to disambiguate between multiple simultaneously-completed signers;
 * extend this (and RecordSignatureCompletionUseCase's per-recipient handling)
 * before relying on it for a multi-signer envelope.
 */
final class WebhookEvent
{
    public function __construct(
        public readonly string $eventType,
        public readonly ?string $providerEnvelopeId,
        public readonly ?string $recipientEmail,
        public readonly array $raw,
    ) {
    }

    public static function fromDocusignPayload(array $payload): self
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $summary = is_array($data['envelopeSummary'] ?? null) ? $data['envelopeSummary'] : [];
        $signers = is_array($summary['recipients']['signers'] ?? null) ? $summary['recipients']['signers'] : [];

        $status = isset($summary['status']) && is_string($summary['status'])
            ? strtolower($summary['status'])
            : null;

        $eventType = $status ?? strtolower(str_replace('envelope-', '', (string) ($payload['event'] ?? 'unknown')));

        $recipientEmail = null;
        $latestSignedAt = null;

        foreach ($signers as $signer) {
            if (! is_array($signer) || ($signer['status'] ?? null) !== 'completed' || empty($signer['email'])) {
                continue;
            }

            $signedAt = $signer['signedDateTime'] ?? null;

            if ($latestSignedAt === null || ($signedAt !== null && $signedAt > $latestSignedAt)) {
                $recipientEmail = (string) $signer['email'];
                $latestSignedAt = $signedAt;
            }
        }

        return new self(
            eventType: $eventType,
            providerEnvelopeId: isset($data['envelopeId']) ? (string) $data['envelopeId'] : null,
            recipientEmail: $recipientEmail,
            raw: $payload,
        );
    }
}
