<?php

namespace PactTrackSDK\SharedResources\Modules\Notification\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use PactTrackSDK\SharedResources\Modules\Signature\Application\DTO\ProviderData;

/**
 * Sent to every ad-hoc co-signer on an envelope the same moment
 * DocumentReadyForSignatureEmail goes to the document's own client: when
 * the envelope first reaches `sent` — see
 * Signature\Application\UseCases\RecordSignatureCompletionUseCase::notifyCoSigners()
 * and .claude/rules/signature.md, "Guest signers".
 *
 * Replaces AdditionalSignerNotificationEmail (removed): a co-signer now
 * signs embedded, inside PactTrack's own branded iframe via a tokenized
 * guest link, rather than being bounced out to DocuSign's own hosted
 * remote-signer email. `signingUrl` carries that link — deliberately no
 * "log in" / "your PactTrack account" language anywhere in this Mailable
 * or its view, since a guest signer has no account to log into.
 */
class GuestSigningInvitationEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ProviderData $providerData,
        public string $signerName,
        public string $documentName,
        public string $clientName,
        public string $signingUrl,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'You\'ve been asked to sign a document',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'notification::emails.guest-signing-invitation',
            with: [
                'providerName' => $this->providerData->business_name,
                'primaryColor' => $this->providerData->primary_color,
                'logoUrl' => $this->providerData->logo_path,
                'signerName' => $this->signerName,
                'documentName' => $this->documentName,
                'clientName' => $this->clientName,
                'signingUrl' => $this->signingUrl,
            ],
        );
    }

    /**
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
