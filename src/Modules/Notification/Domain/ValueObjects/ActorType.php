<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Notification\Domain\ValueObjects;

/**
 * What kind of actor an `audit_logs` row's action belongs to —
 * `audit_logs.actor_type`. Framework-free per the hexagonal rule.
 *
 * `User` covers Owner/Admin/Staff *and* Client — those are all real `users`
 * rows, and the finer Staff-vs-Client split is derived at read time from
 * `user.role` (see AuditLogResource::actorLabel()), not stored twice.
 * `GuestSigner` exists specifically because a guest co-signer has no
 * `users` row at all to attach `user_id` to — see Signer::isGuest() and
 * .claude/rules/notification.md.
 */
enum ActorType: string
{
    case User = 'user';
    case GuestSigner = 'guest_signer';
    case System = 'system';
}
