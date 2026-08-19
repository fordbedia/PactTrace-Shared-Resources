<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Domain\Ports;

/**
 * Issues the API credential a freshly registered user signs in with.
 *
 * Speaks in a user id and returns a string on purpose: the domain has no
 * opinion on whether that string is a Sanctum personal access token, a JWT, or
 * a session identifier. Swapping Sanctum for something else means writing a
 * new adapter in Infrastructure/Auth/ and changing one binding in
 * UserProvider — no application or domain code moves.
 *
 * Narrow by design, matching ESignatureProvider's precedent: token listing,
 * refresh and revocation are not modelled because nothing needs them yet. Add
 * them when a use case actually asks, not in anticipation.
 */
interface AccessTokenIssuer
{
    /**
     * @param  string  $tokenName  Label shown when a user reviews their active
     *                             sessions; also what a revocation would target.
     * @return string  The plain-text credential. Returned exactly once and
     *                 never recoverable afterwards — hand it straight to the
     *                 response and do not log it.
     */
    public function issueFor(int $userId, string $tokenName = 'portal'): string;
}
