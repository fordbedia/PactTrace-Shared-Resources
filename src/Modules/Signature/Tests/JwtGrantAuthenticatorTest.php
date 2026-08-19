<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Tests;

use Illuminate\Support\Facades\Http;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Exceptions\DocusignConsentRequiredException;
use PactTrackSDK\SharedResources\Modules\Signature\Infrastructure\Docusign\JwtGrantAuthenticator;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use RuntimeException;

/**
 * JwtGrantAuthenticator against a mocked HTTP client — no real DocuSign
 * calls or credentials. The private key here is a throwaway RSA keypair
 * generated per test, used only to prove the assertion is well-formed RS256
 * (verifiable with its matching public key), not to assert against a real
 * DocuSign response.
 */
class JwtGrantAuthenticatorTest extends BaseTest
{
    private string $privateKeyPem;

    private string $publicKeyPem;

    protected function setUp(): void
    {
        parent::setUp();

        $keyPair = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        openssl_pkey_export($keyPair, $privateKeyPem);
        $this->privateKeyPem = $privateKeyPem;
        $this->publicKeyPem = openssl_pkey_get_details($keyPair)['key'];
    }

    private function authenticator(string $accountId = 'acct-1'): JwtGrantAuthenticator
    {
        return new JwtGrantAuthenticator(
            clientId: 'client-123',
            impersonatedUserGuid: 'user-guid-456',
            accountId: $accountId,
            authServer: 'account-d.docusign.test',
            privateKeyPem: $this->privateKeyPem,
            consentRedirectUri: 'https://app.pacttrack.test/docusign-return',
        );
    }

    public function test_it_sends_a_well_formed_rs256_assertion_and_resolves_the_matching_account(): void
    {
        Http::fake([
            'account-d.docusign.test/oauth/token' => Http::response(['access_token' => 'tok-abc']),
            'account-d.docusign.test/oauth/userinfo' => Http::response([
                'accounts' => [
                    ['account_id' => 'acct-0', 'base_uri' => 'https://na1.docusign.test', 'is_default' => true],
                    ['account_id' => 'acct-1', 'base_uri' => 'https://na2.docusign.test', 'is_default' => false],
                ],
            ]),
        ]);

        $session = $this->authenticator('acct-1')->authenticate();

        $this->assertSame('tok-abc', $session->accessToken);
        $this->assertSame('acct-1', $session->accountId);
        $this->assertSame('https://na2.docusign.test/restapi', $session->baseUri);

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://account-d.docusign.test/oauth/token') {
                return true;
            }

            $assertion = $request['assertion'];
            [$header, $claims, $signature] = explode('.', $assertion);

            $decodedClaims = json_decode($this->base64UrlDecode($claims), true);
            $this->assertSame('client-123', $decodedClaims['iss']);
            $this->assertSame('user-guid-456', $decodedClaims['sub']);
            $this->assertSame('signature impersonation', $decodedClaims['scope']);

            $verified = openssl_verify(
                "{$header}.{$claims}",
                $this->base64UrlDecode($signature),
                $this->publicKeyPem,
                OPENSSL_ALGO_SHA256,
            );

            $this->assertSame(1, $verified, 'JWT assertion signature does not verify against its own key pair.');

            return $request['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer';
        });
    }

    public function test_it_falls_back_to_the_default_account_when_no_account_matches(): void
    {
        Http::fake([
            'account-d.docusign.test/oauth/token' => Http::response(['access_token' => 'tok-abc']),
            'account-d.docusign.test/oauth/userinfo' => Http::response([
                'accounts' => [
                    ['account_id' => 'acct-0', 'base_uri' => 'https://na1.docusign.test', 'is_default' => true],
                ],
            ]),
        ]);

        $session = $this->authenticator('acct-does-not-exist')->authenticate();

        $this->assertSame('acct-0', $session->accountId);
    }

    public function test_consent_required_is_translated_into_a_clear_exception(): void
    {
        Http::fake([
            'account-d.docusign.test/oauth/token' => Http::response(
                ['error' => 'consent_required'],
                400,
            ),
        ]);

        $this->expectException(DocusignConsentRequiredException::class);

        $this->authenticator()->authenticate();
    }

    public function test_a_token_failure_throws(): void
    {
        Http::fake([
            'account-d.docusign.test/oauth/token' => Http::response(['error' => 'invalid_grant'], 401),
        ]);

        $this->expectException(RuntimeException::class);

        $this->authenticator()->authenticate();
    }

    private function base64UrlDecode(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4), true);
    }
}
