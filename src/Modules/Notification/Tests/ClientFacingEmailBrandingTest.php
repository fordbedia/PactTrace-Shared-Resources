<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Notification\Tests;

use PactTrackSDK\SharedResources\Modules\Notification\Application\DTO\ClientInvitationData;
use PactTrackSDK\SharedResources\Modules\Notification\Mail\ClientInvitationEmail;
use PactTrackSDK\SharedResources\Modules\Notification\Mail\DocumentReadyForSignatureEmail;
use PactTrackSDK\SharedResources\Modules\Notification\Mail\GuestSigningInvitationEmail;
use PactTrackSDK\SharedResources\Modules\Notification\Mail\MilestoneUpdatedEmail;
use PactTrackSDK\SharedResources\Modules\Notification\Mail\SignatureCompletedEmail;
use PactTrackSDK\SharedResources\Modules\Signature\Application\DTO\ProviderData;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;

/**
 * Wiring for `providers.email_sender_name` / `email_reply_to` /
 * `email_powered_by_footer` into the three client-facing Mailables — see
 * .claude/rules/branding.md, "Email Branding". These three fields existed on
 * `providers` and were editable on /dashboard/branding, but were completely
 * dead: `ProviderData` didn't carry them and no Mailable used them.
 */
class ClientFacingEmailBrandingTest extends BaseTest
{
    public function test_from_address_stays_the_platform_address_but_the_display_name_is_the_providers_sender_name(): void
    {
        config(['mail.from.address' => 'hello@pacttrack.com']);

        $mailable = new DocumentReadyForSignatureEmail(
            providerData: $this->providerData(senderName: 'Doe Law Notifications'),
            clientName: 'Alex Client',
            documentName: 'NDA.pdf',
            portalUrl: 'https://app.test/portal',
        );

        $envelope = $mailable->envelope();

        $this->assertSame('hello@pacttrack.com', $envelope->from->address);
        $this->assertSame('Doe Law Notifications', $envelope->from->name);
    }

    public function test_from_display_name_falls_back_to_business_name_when_no_sender_name_set(): void
    {
        $mailable = new DocumentReadyForSignatureEmail(
            providerData: $this->providerData(senderName: null),
            clientName: 'Alex Client',
            documentName: 'NDA.pdf',
            portalUrl: 'https://app.test/portal',
        );

        $this->assertSame('Doe Law', $mailable->envelope()->from->name);
    }

    public function test_email_uses_provider_reply_to_when_set(): void
    {
        $mailable = new ClientInvitationEmail(
            providerData: $this->providerData(replyTo: 'jane@doelaw.example'),
            invitationData: $this->invitationData(),
        );

        $replyTo = $mailable->envelope()->replyTo;
        $this->assertCount(1, $replyTo);
        $this->assertSame('jane@doelaw.example', $replyTo[0]->address);
    }

    public function test_email_has_no_reply_to_when_provider_has_not_set_one(): void
    {
        $mailable = new ClientInvitationEmail(
            providerData: $this->providerData(replyTo: null),
            invitationData: $this->invitationData(),
        );

        $this->assertSame([], $mailable->envelope()->replyTo);
    }

    public function test_sender_name_and_reply_to_apply_regardless_of_plan(): void
    {
        foreach (['starter', 'professional', 'firm'] as $plan) {
            $mailable = new GuestSigningInvitationEmail(
                providerData: $this->providerData(plan: $plan, senderName: 'Doe Law', replyTo: 'jane@doelaw.example'),
                signerName: 'Jordan Guest',
                documentName: 'NDA.pdf',
                clientName: 'Alex Client',
                signingUrl: 'https://app.test/portal/sign?signingLinkToken=abc&envelope=01J0',
            );

            $envelope = $mailable->envelope();
            $this->assertSame('Doe Law', $envelope->from->name, "[{$plan}] sender name should apply regardless of plan");
            $this->assertSame('jane@doelaw.example', $envelope->replyTo[0]->address, "[{$plan}] reply-to should apply regardless of plan");
        }
    }

    public function test_pacttrack_footer_shows_on_starter_only_regardless_of_the_toggle(): void
    {
        // 2026-09-20 rule: "Secured by PactTrack" shows on Starter only;
        // Professional/Firm are white-labeled. The old toggle changes nothing.
        foreach (['starter', 'professional', 'firm'] as $plan) {
            foreach ([true, false] as $toggle) {
                $html = (new DocumentReadyForSignatureEmail(
                    providerData: $this->providerData(plan: $plan, poweredByFooter: $toggle),
                    clientName: 'Alex Client',
                    documentName: 'NDA.pdf',
                    portalUrl: 'https://app.test/portal',
                ))->render();

                if ($plan === 'starter') {
                    $this->assertStringContainsString('Secured by PactTrack', $html, "[{$plan}] footer lost PactTrack branding");
                } else {
                    $this->assertStringNotContainsString('PactTrack', $html, "[{$plan}] footer leaks PactTrack branding");
                }
                $this->assertStringContainsString('Doe Law', $html);
            }
        }
    }

    public function test_the_footer_always_reports_pacttrack_branding_as_shown(): void
    {
        $data = ProviderData::fromArray([
            'owner_user_id' => 1,
            'business_name' => 'Doe Law',
            'subdomain' => 'doelaw',
            'plan' => 'professional',
        ]);

        $this->assertTrue($data->showsPoweredByFooter());
    }

    public function test_internal_notification_emails_are_unaffected_by_any_of_this(): void
    {
        // SignatureCompletedEmail / MilestoneUpdatedEmail render the
        // internal system-notification layout and go to provider-side staff
        // — see .claude/rules/notification.md, "Client-facing vs. internal
        // email branding". Neither takes a ProviderData at all; asserting
        // that stays true is the actual regression guard here.
        $signatureCompleted = new \ReflectionClass(SignatureCompletedEmail::class);
        $milestoneUpdated = new \ReflectionClass(MilestoneUpdatedEmail::class);

        foreach ($signatureCompleted->getConstructor()?->getParameters() ?? [] as $param) {
            $this->assertNotSame(ProviderData::class, (string) $param->getType());
        }

        foreach ($milestoneUpdated->getConstructor()?->getParameters() ?? [] as $param) {
            $this->assertNotSame(ProviderData::class, (string) $param->getType());
        }
    }

    private function providerData(
        string $plan = 'professional',
        ?string $senderName = 'Doe Law',
        ?string $replyTo = null,
        bool $poweredByFooter = false,
    ): ProviderData {
        return new ProviderData(
            id: 1,
            owner_user_id: 1,
            business_name: 'Doe Law',
            subdomain: 'doelaw',
            plan: $plan,
            email_sender_name: $senderName,
            email_reply_to: $replyTo,
            email_powered_by_footer: $poweredByFooter,
        );
    }

    private function invitationData(): ClientInvitationData
    {
        return new ClientInvitationData(
            clientName: 'Alex Client',
            invitedByName: 'Jane Doe',
            email: 'alex@example.com',
            acceptUrl: 'https://app.test/accept-invitation/client?token=abc',
        );
    }
}
