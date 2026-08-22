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

    private function providerData(): ProviderData
    {
        return new ProviderData(
            id: 1,
            owner_user_id: 1,
            business_name: 'Doe Law',
            subdomain: 'doelaw',
        );
    }
}
