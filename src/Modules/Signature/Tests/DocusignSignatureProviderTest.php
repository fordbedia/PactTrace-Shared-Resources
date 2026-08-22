<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Tests;

use Illuminate\Support\Facades\Http;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\ValueObjects\EnvelopeRecipient;
use PactTrackSDK\SharedResources\Modules\Signature\Infrastructure\Docusign\DocusignSignatureProvider;
use PactTrackSDK\SharedResources\Modules\Signature\Infrastructure\Docusign\JwtGrantAuthenticator;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use RuntimeException;

/**
 * DocusignSignatureProvider against a mocked HTTP client — no real network
 * calls. Auth is exercised for real (through a throwaway RSA keypair) since
 * every port method needs a session; the /oauth/* fakes below are the fixed
 * cost of that, not what each test is actually asserting.
 */
class DocusignSignatureProviderTest extends BaseTest
{
    private string $privateKeyPem;

    protected function setUp(): void
    {
        parent::setUp();

        $keyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($keyPair, $privateKeyPem);
        $this->privateKeyPem = $privateKeyPem;
    }

    private function provider(): DocusignSignatureProvider
    {
        return new DocusignSignatureProvider(
            auth: new JwtGrantAuthenticator(
                clientId: 'client-123',
                impersonatedUserGuid: 'user-guid',
                accountId: 'acct-1',
                authServer: 'account-d.docusign.test',
                privateKeyPem: $this->privateKeyPem,
                consentRedirectUri: 'https://app.pacttrack.test/docusign-return',
            ),
            webhookHmacKey: 'shh',
        );
    }

    private function fakeAuth(): void
    {
        Http::fake([
            'account-d.docusign.test/oauth/token' => Http::response(['access_token' => 'tok-abc']),
            'account-d.docusign.test/oauth/userinfo' => Http::response([
                'accounts' => [['account_id' => 'acct-1', 'base_uri' => 'https://na2.docusign.test', 'is_default' => true]],
            ]),
        ]);
    }

    private function recipient(): EnvelopeRecipient
    {
        return new EnvelopeRecipient(name: 'Jane Client', email: 'jane@example.com', clientUserId: '7');
    }

    public function test_create_draft_envelope_sends_the_document_and_recipient_and_returns_the_envelope_id(): void
    {
        $this->fakeAuth();
        Http::fake(['na2.docusign.test/restapi/v2.1/accounts/acct-1/envelopes' => Http::response(['envelopeId' => 'env-1'])]);

        $envelopeId = $this->provider()->createDraftEnvelope('Retainer', 'retainer.pdf', 'pdf-bytes', [$this->recipient()], '42');

        $this->assertSame('env-1', $envelopeId);

        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/envelopes')) {
                return true;
            }

            $this->assertSame('created', $request['status']);
            $this->assertSame(base64_encode('pdf-bytes'), $request['documents'][0]['documentBase64']);
            $this->assertSame('jane@example.com', $request['recipients']['signers'][0]['email']);
            $this->assertSame('7', $request['recipients']['signers'][0]['clientUserId']);

            return true;
        });
    }

    public function test_create_draft_envelope_assigns_sequential_recipient_ids_and_omits_client_user_id_for_remote_signers(): void
    {
        $this->fakeAuth();
        Http::fake(['na2.docusign.test/restapi/v2.1/accounts/acct-1/envelopes' => Http::response(['envelopeId' => 'env-1'])]);

        $coSigner = new EnvelopeRecipient(name: 'Co Signer', email: 'co@example.com', clientUserId: null);

        $this->provider()->createDraftEnvelope('Retainer', 'retainer.pdf', 'pdf-bytes', [$this->recipient(), $coSigner]);

        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/envelopes')) {
                return true;
            }

            $signers = $request['recipients']['signers'];
            $this->assertSame('1', $signers[0]['recipientId']);
            $this->assertSame('7', $signers[0]['clientUserId']);
            $this->assertSame('2', $signers[1]['recipientId']);
            $this->assertSame('co@example.com', $signers[1]['email']);
            $this->assertArrayNotHasKey('clientUserId', $signers[1]);

            return true;
        });
    }

    public function test_create_draft_envelope_throws_on_failure(): void
    {
        $this->fakeAuth();
        Http::fake(['na2.docusign.test/restapi/v2.1/accounts/acct-1/envelopes' => Http::response(['message' => 'bad file'], 400)]);

        $this->expectException(RuntimeException::class);

        $this->provider()->createDraftEnvelope('Retainer', 'retainer.pdf', 'bytes', [$this->recipient()]);
    }

    public function test_sender_view_url_returns_the_embed_url(): void
    {
        $this->fakeAuth();
        Http::fake(['na2.docusign.test/restapi/v2.1/accounts/acct-1/envelopes/env-1/views/sender' => Http::response(['url' => 'https://sign.test/sender'])]);

        $url = $this->provider()->senderViewUrl('env-1', 'https://app.test/return');

        $this->assertSame('https://sign.test/sender', $url);
    }

    public function test_recipient_view_url_returns_a_signing_token(): void
    {
        $this->fakeAuth();
        Http::fake(['na2.docusign.test/restapi/v2.1/accounts/acct-1/envelopes/env-1/views/recipient' => Http::response(['url' => 'https://sign.test/recipient'])]);

        $token = $this->provider()->recipientViewUrl('env-1', $this->recipient(), 'https://app.test/return', '1');

        $this->assertSame('https://sign.test/recipient', $token->signingUrl);
        $this->assertSame('1', $token->providerSignerId);
    }

    /**
     * A co-signer's Signer::provider_signer_id is never '1' — this proves
     * recipientViewUrl() actually sends and echoes back the caller's
     * recipientId, rather than a hardcoded '1', which would otherwise mint
     * an embedded view for the wrong DocuSign recipient. See
     * .claude/rules/signature.md, "Guest signers".
     */
    public function test_recipient_view_url_targets_the_given_recipient_id_not_a_hardcoded_one(): void
    {
        $this->fakeAuth();
        Http::fake(['na2.docusign.test/restapi/v2.1/accounts/acct-1/envelopes/env-1/views/recipient' => Http::response(['url' => 'https://sign.test/recipient'])]);

        $token = $this->provider()->recipientViewUrl('env-1', $this->recipient(), 'https://app.test/return', '2');

        $this->assertSame('2', $token->providerSignerId);
        // Guarded on the endpoint first: assertSent evaluates this closure
        // against every recorded request, including fakeAuth()'s token/
        // userinfo calls, which have no 'recipientId' key at all.
        Http::assertSent(fn ($request) => str_contains($request->url(), '/views/recipient') && $request['recipientId'] === '2');
    }

    public function test_apply_brand_is_a_no_op_when_brand_id_is_null(): void
    {
        $this->provider()->applyBrand('env-1', null);

        Http::assertNothingSent();
    }

    public function test_apply_brand_puts_the_brand_id_on_the_envelope(): void
    {
        $this->fakeAuth();
        Http::fake(['na2.docusign.test/restapi/v2.1/accounts/acct-1/envelopes/env-1' => Http::response([])]);

        $this->provider()->applyBrand('env-1', 'brand-9');

        Http::assertSent(fn ($request) => $request->method() === 'PUT' && $request['brandId'] === 'brand-9');
    }

    public function test_fetch_envelope_status_lowercases_the_response(): void
    {
        $this->fakeAuth();
        Http::fake(['na2.docusign.test/restapi/v2.1/accounts/acct-1/envelopes/env-1' => Http::response(['status' => 'Completed'])]);

        $this->assertSame('completed', $this->provider()->fetchEnvelopeStatus('env-1'));
    }

    public function test_verify_webhook_signature_accepts_a_correctly_signed_payload(): void
    {
        $payload = '{"event":"envelope-completed"}';
        $signature = base64_encode(hash_hmac('sha256', $payload, 'shh', true));

        $this->assertTrue($this->provider()->verifyWebhookSignature($payload, $signature));
    }

    public function test_verify_webhook_signature_rejects_a_tampered_payload(): void
    {
        $signature = base64_encode(hash_hmac('sha256', '{"event":"envelope-completed"}', 'shh', true));

        $this->assertFalse($this->provider()->verifyWebhookSignature('{"event":"envelope-voided"}', $signature));
    }

    public function test_verify_webhook_signature_fails_closed_with_no_configured_key(): void
    {
        $provider = new DocusignSignatureProvider(
            auth: new JwtGrantAuthenticator('c', 'u', 'a', 'account-d.docusign.test', $this->privateKeyPem, 'https://app.pacttrack.test/docusign-return'),
            webhookHmacKey: '',
        );

        $this->assertFalse($provider->verifyWebhookSignature('{}', 'anything'));
    }

    public function test_normalize_webhook_event_delegates_to_the_value_object(): void
    {
        $event = $this->provider()->normalizeWebhookEvent([
            'event' => 'envelope-sent',
            'data' => ['envelopeId' => 'env-1', 'envelopeSummary' => ['status' => 'sent']],
        ]);

        $this->assertSame('sent', $event->eventType);
        $this->assertSame('env-1', $event->providerEnvelopeId);
    }
}
