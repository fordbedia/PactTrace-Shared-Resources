<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports;

/**
 * What happens to a deactivated staffer's assigned work.
 *
 * Mirrors the schema's own `matters.assigned_staff_id`
 * `->nullOnDelete()` intent (see the Matter module's
 * add_assigned_staff_id_to_matters migration): a departing staffer's matters
 * fall back to the provider owner, who is the implicit point of contact for
 * every matter anyway (see `.claude/rules/matter.md`). Since deactivation is a
 * soft remove rather than a row delete, the FK cascade never fires, so this
 * has to be done explicitly.
 *
 * Message threads are deliberately left alone — `message_threads.staff_user_id`
 * is required with no fallback and the Messaging module has no reassignment
 * mechanism (see `.claude/rules/messaging.md`); the thread stays attached for
 * the audit trail and a new thread is the intended path for continued contact.
 *
 * Lives as a port in the User module (the caller) with an Eloquent adapter
 * that touches the Matter module's table — the same direction
 * `EloquentAssignableMatterStaff` already crosses, kept behind an interface so
 * the DeactivateTeamMember use case stays testable with a fake.
 */
interface DepartingStaffReassignment
{
    /**
     * Null out `assigned_staff_id` on every matter (across all workspaces)
     * currently pointing at this user. Returns how many rows changed.
     */
    public function clearMatterAssignments(int $userId): int;
}
