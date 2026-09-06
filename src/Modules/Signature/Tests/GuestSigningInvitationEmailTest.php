<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Tests;

use PactTrackSDK\SharedResources\Modules\Notification\Mail\DocumentReadyForSignatureEmail;
use PactTrackSDK\SharedResources\Modules\Notification\Mail\GuestSigningInvitationEmail;
use PactTrackSDK\SharedResources\Modules\Signature\Application\DTO\ProviderData;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;

/**
 * Guest (no PactTrack account) co-signers must never be told to log in —
 * there's nothing for them to log into. See .claude/rules/signature.md,
 * "Guest signers".
 */
class GuestSigningInvitationEmailTest extends BaseTest
{
    private const LOGIN_ORIENTED_PHRASES = [
        'log in',
        'log into',
        'sign in to',
        'your account',
        'your pacttrack account',
        'your portal',
    ];

    public function test_the_guest_email_never_mentions_logging_in_or_an_account(): void
    {
        $html = strtolower((new GuestSigningInvitationEmail(
            providerData: $this->providerData(),
            signerName: 'Jordan Guest',
            documentName: 'NDA.pdf',
            clientName: 'Alex Client',
            signingUrl: 'https://app.test/portal/sign?signingLinkToken=abc&envelope=01J000000000000000000000',
        ))->render());

        foreach (self::LOGIN_ORIENTED_PHRASES as $phrase) {
            $this->assertStringNotContainsString($phrase, $html, "Guest email unexpectedly contains [{$phrase}].");
        }

        $this->assertStringContainsString('no account', $html);
    }

    public function test_the_account_holder_email_is_unaffected(): void
    {
        $html = strtolower((new DocumentReadyForSignatureEmail(
            providerData: $this->providerData(),
            clientName: 'Alex Client',
            documentName: 'NDA.pdf',
            portalUrl: 'https://app.test/portal/sign?envelope=01J000000000000000000000',
        ))->render());

        $this->assertStringContainsString('portal', $html);
        $this->assertStringContainsString('review &amp; sign', $html);
    }

    public function test_both_client_facing_emails_name_the_workspace_when_given(): void
    {
        $guest = (new GuestSigningInvitationEmail(
            providerData: $this->providerData(),
            signerName: 'Jordan Guest',
            documentName: 'NDA.pdf',
            clientName: 'Alex Client',
            signingUrl: 'https://app.test/portal/sign?signingLinkToken=abc&envelope=01J000000000000000000000',
            workspaceName: 'Redline Litigation',
        ))->render();
        $this->assertStringContainsString('Redline Litigation', $guest);

        $holder = (new DocumentReadyForSignatureEmail(
            providerData: $this->providerData(),
            clientName: 'Alex Client',
            documentName: 'NDA.pdf',
            portalUrl: 'https://app.test/portal/sign?envelope=01J000000000000000000000',
            workspaceName: 'Redline Litigation',
        ))->render();
        $this->assertStringContainsString('Redline Litigation', $holder);
        $this->assertStringContainsString('relates to your work with', $holder);
    }

    public function test_a_blank_workspace_name_adds_no_clause_to_either_email(): void
    {
        $guest = (new GuestSigningInvitationEmail(
            providerData: $this->providerData(),
            signerName: 'Jordan Guest',
            documentName: 'NDA.pdf',
            clientName: 'Alex Client',
            signingUrl: 'https://app.test/portal/sign?signingLinkToken=abc&envelope=01J000000000000000000000',
        ))->render();
        $this->assertStringNotContainsString('regarding <strong', $guest);

        $holder = (new DocumentReadyForSignatureEmail(
            providerData: $this->providerData(),
            clientName: 'Alex Client',
            documentName: 'NDA.pdf',
            portalUrl: 'https://app.test/portal/sign?envelope=01J000000000000000000000',
        ))->render();
        $this->assertStringNotContainsString('relates to your work with', $holder);
    }

    public function test_a_starter_tenant_gets_pacttrack_branding_not_its_own(): void
    {
        $html = (new DocumentReadyForSignatureEmail(
            providerData: $this->providerData(plan: 'starter', logo: 'https://cdn.test/doe-law.png', color: '#7C3AED'),
            clientName: 'Alex Client',
            documentName: 'NDA.pdf',
            portalUrl: 'https://app.test/portal/matter/01J000000000000000000000',
        ))->render();

        // PactTrack wordmark in the footer, and the tenant's own logo/colour
        // are NOT used (plan doesn't allow white-labeling).
        $this->assertStringContainsString('PactTrack', $html);
        $this->assertStringNotContainsString('https://cdn.test/doe-law.png', $html);
        $this->assertStringNotContainsString('#7C3AED', $html);
    }

    public function test_a_professional_or_firm_tenant_is_fully_white_labeled(): void
    {
        foreach (['professional', 'firm'] as $plan) {
            $html = (new DocumentReadyForSignatureEmail(
                providerData: $this->providerData(plan: $plan, logo: 'https://cdn.test/doe-law.png', color: '#7C3AED'),
                clientName: 'Alex Client',
                documentName: 'NDA.pdf',
                portalUrl: 'https://app.test/portal/matter/01J000000000000000000000',
            ))->render();

            $this->assertStringNotContainsString('PactTrack', $html, "[{$plan}] email still mentions PactTrack");
            $this->assertStringContainsString('https://cdn.test/doe-law.png', $html, "[{$plan}] email missing the provider logo");
            $this->assertStringContainsString('#7C3AED', $html, "[{$plan}] email missing the provider accent colour");
            $this->assertStringContainsString('Doe Law', $html);
        }
    }

    private function providerData(string $plan = 'professional', ?string $logo = null, ?string $color = null): ProviderData
    {
        return new ProviderData(
            id: 1,
            owner_user_id: 1,
            business_name: 'Doe Law',
            subdomain: 'doelaw',
            plan: $plan,
            logo_path: $logo,
            primary_color: $color,
        );
    }
}
