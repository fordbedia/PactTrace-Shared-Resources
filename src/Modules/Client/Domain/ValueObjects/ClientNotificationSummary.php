<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Client\Domain\ValueObjects;

/**
 * The client-portal notification bell's state, as one read-only data object —
 * live-computed on every request from data that already has its own source of
 * truth (message threads, envelopes, matters), never a persisted per-event
 * log. See .claude/rules/client.md, "Client portal notification bell", for why
 * this is a computed summary rather than the dormant `notifications` table.
 *
 * Framework-free per the hexagonal rule in CLAUDE.md — `toArray()` is a plain
 * array, not an Illuminate contract.
 */
final class ClientNotificationSummary
{
    public function __construct(
        /** Threads (across all the client's matters) holding an unread staff message. */
        public readonly int $unreadMessageThreadCount,
        /** Envelopes for this client sitting at `sent` / `partially_signed`. */
        public readonly int $documentsAwaitingSignatureCount,
        /** Matters for this client created within the recency window. */
        public readonly int $newMatterCount,
    ) {
    }

    public function total(): int
    {
        return $this->unreadMessageThreadCount
            + $this->documentsAwaitingSignatureCount
            + $this->newMatterCount;
    }

    /**
     * @return array{
     *   unread_message_threads: int,
     *   documents_awaiting_signature: int,
     *   new_matters: int,
     *   total: int
     * }
     */
    public function toArray(): array
    {
        return [
            'unread_message_threads' => $this->unreadMessageThreadCount,
            'documents_awaiting_signature' => $this->documentsAwaitingSignatureCount,
            'new_matters' => $this->newMatterCount,
            'total' => $this->total(),
        ];
    }
}
