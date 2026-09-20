<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Application\UseCases;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Exceptions\EnvelopeAlreadySentException;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Ports\ESignatureProvider;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\ValueObjects\EnvelopeRecipient;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Signer;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * Add/remove an additional signer on an envelope that has NOT been submitted
 * yet. Editable only while the envelope is a draft both locally and on
 * DocuSign's side (live-checked, because our own status only advances on the
 * webhook) — once submitted, EnvelopeAlreadySentException is thrown and the
 * UI hides the controls. The primary signer (the document's client) can
 * never be added, duplicated or removed here.
 */
class ManageDraftEnvelopeSigners
{
    private const DOCUSIGN_DRAFT_STATUS = 'created';

    public function __construct(private readonly ESignatureProvider $eSignatureProvider)
    {
    }

    public function add(Envelope $envelope, string $name, string $email, ?User $actor = null): Signer
    {
        $this->assertEditable($envelope);

        $email = trim($email);
        $clientEmail = strtolower((string) $envelope->client()->value('email'));

        if (strtolower($email) === $clientEmail || $envelope->signers()->whereRaw('LOWER(email) = ?', [strtolower($email)])->exists()) {
            throw ValidationException::withMessages(['email' => 'That person is already a signer on this document.']);
        }

        $recipientId = (string) (max(array_map('intval', $envelope->signers()->pluck('provider_signer_id')->all() ?: [1])) + 1);

        $this->eSignatureProvider->addRecipient(
            $envelope->provider_envelope_id,
            // Embedded, keyed by email — same as co-signers created with the envelope.
            new EnvelopeRecipient(name: trim($name), email: $email, clientUserId: $email),
            $recipientId,
        );

        return DB::transaction(function () use ($envelope, $name, $email, $recipientId, $actor) {
            $signer = Signer::create([
                'envelope_id' => $envelope->id,
                'provider_signer_id' => $recipientId,
                'name' => trim($name),
                'email' => $email,
                'routing_order' => 1,
                'status' => 'pending',
            ]);

            $this->audit($envelope, $actor, 'envelope.signer_added', ['email' => $email]);

            return $signer;
        });
    }

    public function remove(Envelope $envelope, string $email, ?User $actor = null): void
    {
        $this->assertEditable($envelope);

        /** @var Signer|null $signer */
        $signer = $envelope->signers()->whereRaw('LOWER(email) = ?', [strtolower(trim($email))])->first();
        $clientEmail = strtolower((string) $envelope->client()->value('email'));

        if ($signer === null) {
            return; // already gone — idempotent
        }

        if ($signer->provider_signer_id === '1' || strtolower($signer->email) === $clientEmail) {
            throw ValidationException::withMessages(['email' => "The document's client can't be removed."]);
        }

        $this->eSignatureProvider->removeRecipient($envelope->provider_envelope_id, (string) $signer->provider_signer_id);

        DB::transaction(function () use ($envelope, $signer, $actor) {
            $signer->delete();
            $this->audit($envelope, $actor, 'envelope.signer_removed', ['email' => $signer->email]);
        });
    }

    private function assertEditable(Envelope $envelope): void
    {
        if ($envelope->status->value !== 'draft' || $envelope->provider_envelope_id === null) {
            throw EnvelopeAlreadySentException::forProviderStatus($envelope->status->value);
        }

        $live = $this->eSignatureProvider->fetchEnvelopeStatus($envelope->provider_envelope_id);

        if ($live !== self::DOCUSIGN_DRAFT_STATUS) {
            throw EnvelopeAlreadySentException::forProviderStatus($live);
        }
    }

    private function audit(Envelope $envelope, ?User $actor, string $action, array $metadata): void
    {
        AuditLog::create([
            'provider_id' => $envelope->provider_id,
            'user_id' => $actor?->id,
            'action' => $action,
            'auditable_type' => Envelope::class,
            'auditable_id' => $envelope->id,
            'metadata' => $metadata,
        ]);
    }
}
