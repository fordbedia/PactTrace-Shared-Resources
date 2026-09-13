<?php

namespace PactTrackSDK\SharedResources\Modules\Notification\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use PactTrackSDK\SharedResources\Modules\Signature\Application\DTO\ProviderData;

/**
 * Sent when a Signature\Models\Envelope first reaches `sent` (i.e. the
 * tenant finished tagging fields in DocuSign's embedded Sender View and
 * sent) — see Signature\Application\UseCases\RecordSignatureCompletionUseCase::notifyClient()
 * and .claude/rules/signature.md, "Flow A" step 5. Same DTO-based pattern as
 * ClientInvitationEmail: scalars in, no Eloquent models, so this stays easy
 * to queue/serialize.
 */
class DocumentReadyForSignatureEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ProviderData $providerData,
        public string $clientName,
        public string $documentName,
        public string $portalUrl,
        // The workspace (practice line) this signing request belongs to — a
        // client of a provider running more than one practice benefits from
        // knowing which one. Blank when unresolvable; the line is then omitted.
        public string $workspaceName = '',
    ) {
    }

    /**
     * `from`/`replyTo` use the tenant's own Email Branding settings — see
     * ClientInvitationEmail::envelope()'s docblock for the full rationale
     * (ungated sender name/reply-to; FROM address stays the platform's own).
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'A document is ready for your signature',
            from: new Address(
                (string) config('mail.from.address'),
                $this->providerData->email_sender_name ?: $this->providerData->business_name,
            ),
            replyTo: $this->providerData->email_reply_to !== null
                ? [new Address($this->providerData->email_reply_to)]
                : [],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'notification::emails.document-ready-for-signature',
            with: [
                'brandingEnabled' => $this->providerData->allowsCustomBranding(),
                'poweredByFooter' => $this->providerData->showsPoweredByFooter(),
                'providerName' => $this->providerData->business_name,
                'primaryColor' => $this->providerData->primary_color,
                'logoUrl' => $this->providerData->logo_url,
                'clientName' => $this->clientName,
                'documentName' => $this->documentName,
                'portalUrl' => $this->portalUrl,
                'workspaceName' => $this->workspaceName,
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
