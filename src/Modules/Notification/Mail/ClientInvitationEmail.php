<?php

namespace PactTrackSDK\SharedResources\Modules\Notification\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use PactTrackSDK\SharedResources\Modules\Notification\Application\DTO\ClientInvitationData;
use PactTrackSDK\SharedResources\Modules\Signature\Application\DTO\ProviderData;

class ClientInvitationEmail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(public ProviderData $providerData, public ClientInvitationData $invitationData)
    {}

    /**
     * Get the message envelope.
     *
     * `from`/`replyTo` use the tenant's own Email Branding settings
     * (`providers.email_sender_name` / `email_reply_to`) — every plan, per
     * Ed 2026-09-12 (deliverability, not visual branding). The FROM
     * *address* stays the platform's own verified sending address
     * regardless of plan (an arbitrary unverified address would fail
     * SPF/DKIM and land in spam) — only the display NAME is the tenant's;
     * `replyTo` is safe to set to the tenant's own mailbox since it's not
     * spoofing the envelope sender. See .claude/rules/branding.md.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'You have been invited to join a client!',
            from: new Address(
                (string) config('mail.from.address'),
                $this->providerData->email_sender_name ?: $this->providerData->business_name,
            ),
            replyTo: $this->providerData->email_reply_to !== null
                ? [new Address($this->providerData->email_reply_to)]
                : [],
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'notification::emails.client-invitation',
            with: [
                'brandingEnabled' => $this->providerData->allowsCustomBranding(),
                'poweredByFooter' => $this->providerData->showsPoweredByFooter(),
                'providerName' => $this->providerData->business_name,
                'primaryColor' => $this->providerData->primary_color,
                'logoUrl' => $this->providerData->logo_url,
                'clientName' => $this->invitationData->clientName,
                'invitedByName' => $this->invitationData->invitedByName,
                'email' => $this->invitationData->email,
                'acceptUrl' => $this->invitationData->acceptUrl,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
