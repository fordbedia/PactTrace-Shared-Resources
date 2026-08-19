<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Infrastructure\Docusign;

use Illuminate\Support\Facades\Http;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Exceptions\DocusignConsentRequiredException;
use RuntimeException;

/**
 * Mints a DocuSign access token via JWT Grant (server-to-server,
 * impersonating a system user — no interactive login for anyone, ever).
 * Deliberately stateless: a fresh assertion is built and exchanged on every
 * call, no token is cached beyond the caller's own use of the returned
 * DocusignSession — access tokens are 10-minute-lived, which makes
 * request-lifecycle-only use simpler and safer than a shared cache that
 * could hand out an expired token.
 *
 * The RSA private key is signed with directly via openssl_sign() rather
 * than a JWT library — RS256 over a two-segment base64url(header).
 * base64url(claims) string is the entire algorithm, and pulling in a
 * dependency for it would be the "generic mapping registry" kind of
 * over-abstraction the feature spec explicitly warns against elsewhere.
 */
final class JwtGrantAuthenticator
{
    private const TOKEN_TTL_SECONDS = 3600;

    public function __construct(
        private readonly string $clientId,
        private readonly string $impersonatedUserGuid,
        private readonly string $accountId,
        private readonly string $authServer,
        private readonly string $privateKeyPem,
        private readonly string $consentRedirectUri,
    ) {
    }

    public function authenticate(): DocusignSession
    {
        $tokenResponse = Http::asForm()->post("https://{$this->authServer}/oauth/token", [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $this->buildAssertion(),
        ]);

        if ($tokenResponse->status() === 400 && str_contains($tokenResponse->body(), 'consent_required')) {
            throw DocusignConsentRequiredException::forClientId($this->clientId, $this->authServer, $this->consentRedirectUri);
        }

        if ($tokenResponse->failed()) {
            throw new RuntimeException(
                "DocuSign JWT grant failed ({$tokenResponse->status()}): {$tokenResponse->body()}"
            );
        }

        $accessToken = $tokenResponse->json('access_token');

        if (! is_string($accessToken) || $accessToken === '') {
            throw new RuntimeException('DocuSign JWT grant returned no access token.');
        }

        $userInfoResponse = Http::withToken($accessToken)->get("https://{$this->authServer}/oauth/userinfo");

        if ($userInfoResponse->failed()) {
            throw new RuntimeException(
                "DocuSign /oauth/userinfo failed ({$userInfoResponse->status()}): {$userInfoResponse->body()}"
            );
        }

        $accounts = (array) $userInfoResponse->json('accounts', []);
        $account = $this->resolveAccount($accounts);

        if ($account === null) {
            throw new RuntimeException(
                "DocuSign account [{$this->accountId}] was not found among the impersonated user's accounts."
            );
        }

        $baseUri = $account['base_uri'] ?? null;

        if (! is_string($baseUri) || $baseUri === '') {
            throw new RuntimeException('DocuSign /oauth/userinfo returned an account with no base_uri.');
        }

        return new DocusignSession(
            accessToken: $accessToken,
            baseUri: rtrim($baseUri, '/') . '/restapi',
            accountId: (string) $account['account_id'],
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $accounts
     * @return array<string, mixed>|null
     */
    private function resolveAccount(array $accounts): ?array
    {
        foreach ($accounts as $account) {
            if (is_array($account) && (string) ($account['account_id'] ?? '') === $this->accountId) {
                return $account;
            }
        }

        // No configured target account (or it didn't match) — fall back to
        // whichever account /oauth/userinfo marks as the default, so local
        // dev works with a single-account sandbox without DOCUSIGN_ACCOUNT_ID
        // needing to be filled in first.
        foreach ($accounts as $account) {
            if (is_array($account) && ($account['is_default'] ?? false) === true) {
                return $account;
            }
        }

        return $accounts[0] ?? null;
    }

    private function buildAssertion(): string
    {
        $now = time();

        $header = $this->base64UrlEncode(json_encode(
            ['alg' => 'RS256', 'typ' => 'JWT'],
            JSON_THROW_ON_ERROR,
        ));

        $claims = $this->base64UrlEncode(json_encode(
            [
                'iss' => $this->clientId,
                'sub' => $this->impersonatedUserGuid,
                'aud' => $this->authServer,
                'iat' => $now,
                'exp' => $now + self::TOKEN_TTL_SECONDS,
                'scope' => 'signature impersonation',
            ],
            JSON_THROW_ON_ERROR,
        ));

        $signingInput = "{$header}.{$claims}";
        $signature = '';

        $signed = openssl_sign($signingInput, $signature, $this->privateKeyPem, OPENSSL_ALGO_SHA256);

        if (! $signed) {
            throw new RuntimeException(
                'Failed to sign the DocuSign JWT assertion — check that DOCUSIGN_PRIVATE_KEY_PATH points at a '
                . 'valid RSA private key PEM registered against DOCUSIGN_CLIENT_ID.'
            );
        }

        return "{$signingInput}." . $this->base64UrlEncode($signature);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
