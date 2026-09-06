<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects;

/**
 * An action that is only allowed while the acting tenant's plan has room for
 * it — the closed set {@see PlanPolicy} knows how to evaluate today.
 *
 * Framework-free by the hexagonal rule in CLAUDE.md. Add a case here only
 * once a real enforcement site exists for it (see .claude/rules/plan.md,
 * "Where each limit is enforced today") — this is not a place to pre-declare
 * every limit in the matrix ahead of the feature that gates it.
 */
enum GatedAction: string
{
    case UploadDocument = 'upload_document';
    case PrepareForSignature = 'prepare_for_signature';
    case InviteClient = 'invite_client';
    case InviteStaff = 'invite_staff';
}
