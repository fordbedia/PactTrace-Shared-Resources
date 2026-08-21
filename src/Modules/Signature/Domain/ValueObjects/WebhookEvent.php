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
 * `completedSignerEmails` lists every recipient this payload reports as
 * 'completed' — used to keep each matching local `Signer` row's
 * status/email in sync (see RecordSignatureCompletionUseCase). DocuSign
 * delivers the full recipient list on every Connect payload, so this is
 * simply every 'completed' entry in it, not just one.
 */
final class WebhookEvent
{
    /**
     * @param string[] $completedSignerEmails
     */
    public function __construct(
        public readonly string $eventType,
        public readonly ?string $providerEnvelopeId,
        public readonly array $completedSignerEmails,
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

        $completedSignerEmails = [];

        foreach ($signers as $signer) {
            if (! is_array($signer) || ($signer['status'] ?? null) !== 'completed' || empty($signer['email'])) {
                continue;
            }

            $completedSignerEmails[] = (string) $signer['email'];
        }

        return new self(
            eventType: $eventType,
            providerEnvelopeId: isset($data['envelopeId']) ? (string) $data['envelopeId'] : null,
            completedSignerEmails: array_values(array_unique($completedSignerEmails)),
            raw: $payload,
        );
    }
}
