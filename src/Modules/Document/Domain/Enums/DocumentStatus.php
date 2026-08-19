<?php

declare(strict_types=1);

namespace PactTraceSDK\SharedResources\Modules\Document\Domain\Enums;

/**
 * A document's position in the send/sign lifecycle — see
 * .claude/rules/document.md, "Document Deletion & Archival Rules".
 *
 * draft -> sent -> partially_signed -> completed, with `voided` reachable
 * from `sent` or `partially_signed` as the cancellation path for an
 * in-flight signature request. Framework-free by design (hexagonal rule in
 * the top-level CLAUDE.md) — this is domain vocabulary, not a spatie/Eloquent
 * concern.
 */
enum DocumentStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case PartiallySigned = 'partially_signed';
    case Completed = 'completed';
    case Voided = 'voided';

    /**
     * Only an in-flight envelope (sent, or partway through its signer
     * routing) can be cancelled via Void — see DocumentDeletionPolicy for
     * why a draft is deleted instead, and a completed/voided document is
     * neither.
     */
    public function isVoidable(): bool
    {
        return match ($this) {
            self::Sent, self::PartiallySigned => true,
            default => false,
        };
    }
}
