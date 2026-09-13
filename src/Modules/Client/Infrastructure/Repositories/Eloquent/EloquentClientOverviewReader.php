<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Client\Infrastructure\Repositories\Eloquent;

use Illuminate\Support\Facades\DB;
use PactTrackSDK\SharedResources\Modules\Client\Application\Ports\Query\ClientOverviewReader;
use PactTrackSDK\SharedResources\Modules\Document\Domain\Enums\DocumentStatus;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\Matter\Models\Matter;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\MessageThread;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\Modules\Workspace\Models\Scopes\WorkspaceScope;

/**
 * Counts and reads each Overview-tab figure in SQL.
 *
 * Matter/Document/Envelope/MessageThread reads drop `WorkspaceScope` — same
 * rationale as {@see EloquentClientNotificationSignalReader}: the Client
 * Detail page answers "across everything this client has with this
 * provider", not just the currently active workspace. SoftDeletes scopes
 * are left on, so archived threads / soft-deleted documents don't count.
 */
final class EloquentClientOverviewReader implements ClientOverviewReader
{
    private const OPEN_MATTER_STATUSES = ['active', 'on_hold'];
    private const CLOSED_MATTER_STATUSES = ['completed', 'cancelled'];

    public function activeMatterCount(int $clientId): int
    {
        return $this->matterQuery($clientId)
            ->whereIn('status', self::OPEN_MATTER_STATUSES)
            ->count();
    }

    public function closedMatterCount(int $clientId): int
    {
        return $this->matterQuery($clientId)
            ->whereIn('status', self::CLOSED_MATTER_STATUSES)
            ->count();
    }

    public function documentCount(int $clientId): int
    {
        return $this->documentQuery($clientId)->count();
    }

    public function documentsOutForSignatureCount(int $clientId): int
    {
        return $this->documentQuery($clientId)
            ->whereIn('status', [
                DocumentStatus::Sent->value,
                DocumentStatus::PartiallySigned->value,
            ])
            ->count();
    }

    public function envelopeCount(int $clientId): int
    {
        return Envelope::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->where('client_id', $clientId)
            ->count();
    }

    public function recentMatters(int $clientId, int $limit): array
    {
        $matters = $this->matterQuery($clientId)
            ->withCount('documents')
            ->latest()
            ->limit($limit)
            ->get(['id', 'public_id', 'name', 'status', 'created_at']);

        if ($matters->isEmpty()) {
            return [];
        }

        $envelopeCounts = DB::table('envelopes')
            ->join('documents', 'documents.id', '=', 'envelopes.document_id')
            ->whereIn('documents.matter_id', $matters->pluck('id'))
            ->selectRaw('documents.matter_id, count(*) as envelopes_count')
            ->groupBy('documents.matter_id')
            ->pluck('envelopes_count', 'documents.matter_id');

        return $matters
            ->map(static fn (Matter $matter): array => [
                'id' => $matter->id,
                'public_id' => $matter->public_id,
                'name' => $matter->name,
                'status' => $matter->status,
                'opened_at' => $matter->created_at?->toIso8601String(),
                'documents_count' => (int) $matter->documents_count,
                'envelopes_count' => (int) ($envelopeCounts[$matter->id] ?? 0),
            ])
            ->all();
    }

    public function messageThreadCount(int $clientId): int
    {
        return $this->threadQuery($clientId)->count();
    }

    public function unreadMessageThreadCountForStaff(int $clientId, int $staffUserId): int
    {
        return $this->threadQuery($clientId)
            ->withUnreadFor($staffUserId)
            ->count();
    }

    private function matterQuery(int $clientId)
    {
        return Matter::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->where('client_id', $clientId);
    }

    private function documentQuery(int $clientId)
    {
        return Document::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->where('client_id', $clientId)
            ->whereNull('archived_at');
    }

    private function threadQuery(int $clientId)
    {
        return MessageThread::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->where('client_id', $clientId);
    }
}
