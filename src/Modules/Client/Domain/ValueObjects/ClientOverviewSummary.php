<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Client\Domain\ValueObjects;

/**
 * Everything the Client Detail page's Overview tab renders
 * (`/dashboard/clients`, see .claude/rules/client.md) — the four stat
 * cards, the "Open Matters" panel, and the "Recent Activity" feed — as one
 * read-only data object assembled by
 * {@see \PactTrackSDK\SharedResources\Modules\Client\Application\UseCases\GetClientOverview}.
 *
 * Framework-free per the hexagonal rule in CLAUDE.md — `toArray()` is a
 * plain array, not an Illuminate contract. Mirrors
 * `ClientNotificationSummary`'s shape/placement for the same reason: this is
 * a live-computed cross-module read, not a persisted projection.
 */
final class ClientOverviewSummary
{
    public function __construct(
        public readonly int $activeMatterCount,
        public readonly int $closedMatterCount,
        public readonly int $documentCount,
        public readonly int $documentsOutForSignatureCount,
        public readonly int $envelopeCount,
        public readonly int $envelopesAwaitingSignatureCount,
        public readonly int $messageThreadCount,
        public readonly int $unreadMessageThreadCount,
        /** @var list<array{id: int, public_id: string, name: string, status: string, opened_at: ?string, documents_count: int, envelopes_count: int}> */
        public readonly array $openMatters,
        /** @var list<array{id: int, action: string, auditable_type: ?string, at: ?string}> */
        public readonly array $recentActivity,
    ) {
    }

    public function totalMatterCount(): int
    {
        return $this->activeMatterCount + $this->closedMatterCount;
    }

    /**
     * @return array{
     *   matters: array{active: int, closed: int, total: int},
     *   documents: array{total: int, out_for_signature: int},
     *   envelopes: array{total: int, awaiting_signature: int},
     *   messages: array{total: int, unread: int},
     *   open_matters: list<array<string, mixed>>,
     *   recent_activity: list<array<string, mixed>>
     * }
     */
    public function toArray(): array
    {
        return [
            'matters' => [
                'active' => $this->activeMatterCount,
                'closed' => $this->closedMatterCount,
                'total' => $this->totalMatterCount(),
            ],
            'documents' => [
                'total' => $this->documentCount,
                'out_for_signature' => $this->documentsOutForSignatureCount,
            ],
            'envelopes' => [
                'total' => $this->envelopeCount,
                'awaiting_signature' => $this->envelopesAwaitingSignatureCount,
            ],
            'messages' => [
                'total' => $this->messageThreadCount,
                'unread' => $this->unreadMessageThreadCount,
            ],
            'open_matters' => $this->openMatters,
            'recent_activity' => $this->recentActivity,
        ];
    }
}
