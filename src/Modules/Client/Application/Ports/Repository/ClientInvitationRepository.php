<?php

namespace PactTrackSDK\SharedResources\Modules\Client\Application\Ports\Repository;

use PactTrackSDK\SharedResources\Modules\Client\Models\ClientInvitation;

interface ClientInvitationRepository
{
	/**
	 * @param  array<string, mixed>  $attributes
	 */
	public function create(array $attributes): ClientInvitation;

	/**
	 * The one lookup the accept flow needs: a token that still means
	 * something. Deliberately excludes expired and already-accepted rows at
	 * the query level rather than leaving that check to the caller — a stale
	 * or reused link should read as "not found," the same as a wrong one.
	 */
	public function findValidByToken(string $token): ?ClientInvitation;

	public function markAccepted(ClientInvitation $invitation): void;

	/**
	 * Deletes any still-pending (unaccepted, unexpired-or-not) invitations for
	 * a client before a fresh one is issued, so re-inviting the same client
	 * can't leave two valid tokens outstanding — only the newest email's link
	 * should work.
	 */
	public function invalidatePendingForClient(int $clientId): void;
}
