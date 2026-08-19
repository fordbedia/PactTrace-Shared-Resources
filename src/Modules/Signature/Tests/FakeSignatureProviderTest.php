<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Tests;

use PactTrackSDK\SharedResources\Modules\Signature\Domain\ValueObjects\EnvelopeRecipient;
use PactTrackSDK\SharedResources\Modules\Signature\Infrastructure\Fake\FakeSignatureProvider;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;

class FakeSignatureProviderTest extends BaseTest
{
    private function recipient(): EnvelopeRecipient
    {
        return new EnvelopeRecipient(name: 'Client Name', email: 'client@example.com', clientUserId: '42');
    }

    public function test_it_never_returns_empty_identifiers(): void
    {
        $provider = new FakeSignatureProvider();

        $envelopeId = $provider->createDraftEnvelope('Title', 'file.pdf', 'bytes', $this->recipient());
        $this->assertNotEmpty($envelopeId);
        $this->assertNotEmpty($provider->senderViewUrl($envelopeId, 'https://app.test/return'));

        $token = $provider->recipientViewUrl($envelopeId, $this->recipient(), 'https://app.test/return');
        $this->assertNotEmpty($token->token);
        $this->assertNotEmpty($token->providerSignerId);
        $this->assertStringContainsString($token->token, $token->signingUrl);
    }

    public function test_apply_brand_is_a_harmless_no_op(): void
    {
        $provider = new FakeSignatureProvider();

        $provider->applyBrand('env-1', null);
        $provider->applyBrand('env-1', 'brand-123');

        $this->addToAssertionCount(1);
    }

    public function test_fetch_envelope_status_returns_a_deterministic_value(): void
    {
        $provider = new FakeSignatureProvider();

        $this->assertSame('sent', $provider->fetchEnvelopeStatus('env-1'));
    }

    public function test_it_always_accepts_webhook_signatures(): void
    {
        $provider = new FakeSignatureProvider();

        $this->assertTrue($provider->verifyWebhookSignature('{}', null));
    }

    public function test_it_normalizes_payloads_the_same_way_as_the_real_adapter(): void
    {
        $provider = new FakeSignatureProvider();

        $event = $provider->normalizeWebhookEvent([
            'event' => 'envelope-sent',
            'data' => ['envelopeId' => 'env-9', 'envelopeSummary' => ['status' => 'sent']],
        ]);

        $this->assertSame('sent', $event->eventType);
        $this->assertSame('env-9', $event->providerEnvelopeId);
    }
}
