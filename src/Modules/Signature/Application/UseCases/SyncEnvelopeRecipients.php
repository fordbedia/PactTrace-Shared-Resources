<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Application\UseCases;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Ports\ESignatureProvider;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\ValueObjects\ProviderRecipient;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Signer;
use PactTrackSDK\SharedResources\Modules\User\Models\User;
use Throwable;

/**
 * While an envelope is still a draft, DocuSign is the source of truth for
 * its recipients: the tenant can add, edit or remove signers inside the
 * embedded Sender View and then leave without ever pressing Send, so no
 * webhook fires and PactTrack would otherwise never learn about them. This
 * pulls DocuSign's recipient list into `signers` — called when the Sender
 * View returns (any event, not just `send`) and when the prepare modal
 * reopens. See .claude/rules/signature.md, "Recipient sync".
 *
 * Rules:
 * - The primary signer (the document's own client — DocuSign clientUserId ==
 *   Client::id, recipientId '1' by construction) is never created, changed
 *   or deleted here.
 * - Additional signers are upserted by DocuSign recipientId, and local ones
 *   DocuSign no longer has are removed.
 * - Draft-only: once the envelope leaves `draft` locally, the webhook owns
 *   recipient state and this is a no-op.
 * - Idempotent, transactional, audit-logged only when something changed.
 */
class SyncEnvelopeRecipients
{
    /** Minimum seconds between non-forced provider reads for one envelope. */
    private const THROTTLE_SECONDS = 10;

    private const PRIMARY_RECIPIENT_ID = '1';

    public function __construct(private readonly ESignatureProvider $eSignatureProvider)
    {
    }

    /**
     * @param bool $force Bypass the short throttle (the return-from-DocuSign
     *     call always wants a fresh read; the modal-open refresh doesn't).
     *
     * @return array{synced: bool, added: int, updated: int, removed: int}
     *
     * @throws Throwable when DocuSign can't be reached — callers degrade to
     *     the DB copy rather than surfacing it.
     */
    public function handle(Envelope $envelope, ?User $actor = null, bool $force = false): array
    {
        $none = ['synced' => false, 'added' => 0, 'updated' => 0, 'removed' => 0];

        if ($envelope->status->value !== 'draft' || $envelope->provider_envelope_id === null) {
            return $none;
        }

        if (! $force && ! Cache::add("envelope-recipient-sync:{$envelope->id}", 1, self::THROTTLE_SECONDS)) {
            return $none;
        }

        $remote = $this->eSignatureProvider->fetchRecipients($envelope->provider_envelope_id);
        $clientUserId = (string) $envelope->client_id;

        $primaryRemoteId = null;
        $additional = [];

        foreach ($remote as $recipient) {
            if ($recipient->clientUserId === $clientUserId) {
                $primaryRemoteId = $recipient->recipientId;
            } else {
                $additional[$recipient->recipientId] = $recipient;
            }
        }

        // A recipient sitting on recipientId '1' is always the primary by
        // construction, even if DocuSign dropped its clientUserId.
        unset($additional[self::PRIMARY_RECIPIENT_ID]);

        $protectedIds = array_filter([self::PRIMARY_RECIPIENT_ID, $primaryRemoteId]);

        $result = DB::transaction(function () use ($envelope, $additional, $protectedIds, $actor) {
            $added = $updated = $removed = 0;

            $local = Signer::query()->where('envelope_id', $envelope->id)->lockForUpdate()->get()
                ->keyBy('provider_signer_id');

            foreach ($additional as $recipientId => $recipient) {
                /** @var Signer|null $signer */
                $signer = $local->get($recipientId);

                if ($signer === null) {
                    Signer::create([
                        'envelope_id' => $envelope->id,
                        'provider_signer_id' => $recipientId,
                        'name' => $recipient->name,
                        'email' => $recipient->email,
                        'routing_order' => $recipient->routingOrder,
                        'status' => $recipient->status,
                    ]);
                    $added++;

                    continue;
                }

                $signer->fill([
                    'name' => $recipient->name,
                    'email' => $recipient->email,
                    'routing_order' => $recipient->routingOrder,
                ]);

                if ($signer->isDirty()) {
                    $signer->save();
                    $updated++;
                }
            }

            foreach ($local as $recipientId => $signer) {
                if (in_array((string) $recipientId, $protectedIds, true) || isset($additional[$recipientId])) {
                    continue;
                }

                $signer->delete();
                $removed++;
            }

            if ($added + $updated + $removed > 0) {
                AuditLog::create([
                    'provider_id' => $envelope->provider_id,
                    'user_id' => $actor?->id,
                    'action' => 'envelope.signers_synced',
                    'auditable_type' => Envelope::class,
                    'auditable_id' => $envelope->id,
                    'metadata' => ['source' => 'docusign', 'added' => $added, 'updated' => $updated, 'removed' => $removed],
                ]);
            }

            return ['synced' => true, 'added' => $added, 'updated' => $updated, 'removed' => $removed];
        });

        $this->embedRemoteRecipients($envelope, $additional);

        return $result;
    }

    /**
     * Signers added in DocuSign's own UI are "remote" (no clientUserId), which
     * means DocuSign would email them itself and refuse PactTrack's embedded
     * guest view. Best-effort promotion to embedded, keyed by email exactly as
     * PrepareEnvelopeForSignature does for co-signers it creates itself.
     *
     * @param array<string, ProviderRecipient> $additional
     */
    private function embedRemoteRecipients(Envelope $envelope, array $additional): void
    {
        $toEmbed = [];

        foreach ($additional as $recipientId => $recipient) {
            if ($recipient->clientUserId === null) {
                $toEmbed[(string) $recipientId] = $recipient->email;
            }
        }

        if ($toEmbed === []) {
            return;
        }

        try {
            $this->eSignatureProvider->embedRecipients($envelope->provider_envelope_id, $toEmbed);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
