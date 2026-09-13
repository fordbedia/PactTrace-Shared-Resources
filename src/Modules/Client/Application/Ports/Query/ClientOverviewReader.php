<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Client\Application\Ports\Query;

/**
 * Reads the cross-module figures behind the Client Detail page's Overview
 * tab (`/dashboard/clients`, see .claude/rules/client.md) — one method per
 * stat/panel, same "each figure comes from a different module's own table"
 * shape as {@see ClientNotificationSignalReader}. The Eloquent adapter reads
 * those modules' models directly rather than each one growing a bespoke
 * "for this client" query it has no other caller for.
 */
interface ClientOverviewReader
{
    /** Matters for this client currently `active` or `on_hold`. */
    public function activeMatterCount(int $clientId): int;

    /** Matters for this client that reached `completed` or `cancelled`. */
    public function closedMatterCount(int $clientId): int;

    /** Every document filed for this client, regardless of status. */
    public function documentCount(int $clientId): int;

    /** Documents for this client currently `sent` or `partially_signed`. */
    public function documentsOutForSignatureCount(int $clientId): int;

    /** Every envelope raised for this client, regardless of status. */
    public function envelopeCount(int $clientId): int;

    /**
     * The client's most recently opened matters, newest first — backs the
     * Overview tab's "Open Matters" panel.
     *
     * @return list<array{id: int, public_id: string, name: string, status: string, opened_at: ?string, documents_count: int, envelopes_count: int}>
     */
    public function recentMatters(int $clientId, int $limit): array;

    /**
     * Non-archived message threads for this client.
     */
    public function messageThreadCount(int $clientId): int;

    /**
     * Non-archived message threads for this client holding a client message
     * `$staffUserId` (the staff member viewing the Client Detail page) has
     * not yet read.
     */
    public function unreadMessageThreadCountForStaff(int $clientId, int $staffUserId): int;
}
