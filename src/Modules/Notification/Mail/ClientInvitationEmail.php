<?php

namespace PactTraceSDK\SharedResources\Modules\Notification\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use PactTraceSDK\SharedResources\Modules\Notification\Application\DTO\ClientInvitationData;
use PactTraceSDK\SharedResources\Modules\Signature\Application\DTO\ProviderData;
use PactTraceSDK\SharedResources\Modules\User\Domain\Enum\SubscriptionPlan;

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
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'You have been invited to join a client!',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: SubscriptionPlan::Starter->getPlan()
				? 'notification::emails.client-invitation'
				: 'notification::client-facing-provider-invitation',
            with: [
                'providerName' => $this->providerData->business_name,
                'primaryColor' => $this->providerData->primary_color,
                'logoUrl' => $this->providerData->logo_path,
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
