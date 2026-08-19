<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Infrastructure\Auth;

use PactTrackSDK\SharedResources\Modules\User\Domain\Ports\AccessTokenIssuer;
use PactTrackSDK\SharedResources\Modules\User\Models\User;
use RuntimeException;

/**
 * Sanctum adapter for AccessTokenIssuer.
 *
 * This is what Infrastructure/Auth/ is for: the half of authentication that is
 * pure I/O against a third-party mechanism. It knows about Sanctum's
 * personal_access_tokens table and its plainTextToken contract, and nothing
 * about registration — which is why RegisterProvider (orchestration) lives in
 * Application/UseCases/ and this lives here.
 *
 * The Next.js frontend authenticates cross-origin, so token-based Sanctum is
 * the right mode rather than its SPA/cookie mode; the `web` session guard in
 * config/auth.php stays for anything server-rendered.
 */
final class SanctumTokenIssuer implements AccessTokenIssuer
{
    public function issueFor(int $userId, string $tokenName = 'portal'): string
    {
        $user = User::query()->find($userId);

        if ($user === null) {
            throw new RuntimeException("Cannot issue a token: user [{$userId}] does not exist.");
        }

        // Abilities are intentionally left as ['*']: PactTrack authorises
        // through spatie permissions and TenantScopedPolicy, so encoding a
        // second, parallel permission model into the token would give two
        // sources of truth for the same question. See .claude/rules/user.md.
        return $user->createToken($tokenName)->plainTextToken;
    }
}
