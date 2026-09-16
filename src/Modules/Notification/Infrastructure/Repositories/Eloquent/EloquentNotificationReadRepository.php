<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Notification\Infrastructure\Repositories\Eloquent;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PactTrackSDK\SharedResources\Modules\Notification\Application\Ports\Repository\NotificationReadRepository;
use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTrackSDK\SharedResources\Modules\Notification\Models\NotificationRead;

class EloquentNotificationReadRepository implements NotificationReadRepository
{
    public function markRead(int $userId, int $auditLogId): void
    {
        NotificationRead::query()->firstOrCreate(
            ['user_id' => $userId, 'audit_log_id' => $auditLogId],
            ['read_at' => Carbon::now()],
        );
    }

    /**
     * `AuditLog::query()` carries its usual global scopes (WorkspaceScope), so
     * this reaches exactly the rows the bell's own feed query can see for this
     * user right now — never a different, wider set.
     *
     * One `INSERT ... SELECT`, not a fetch-then-insert: a tenant with a long
     * audit history can hold hundreds of thousands of `audit_logs` rows, and
     * pulling every id into PHP just to write it straight back out would load
     * the whole table into memory on a single button click. The `SELECT` also
     * excludes rows this user has already read, so re-clearing an
     * already-clear feed inserts nothing at all rather than relying on the
     * unique index to absorb a full-table upsert.
     */
    public function markAllRead(int $userId, int $providerId): void
    {
        $now = Carbon::now();

        $source = AuditLog::query()
            ->where('provider_id', $providerId)
            ->whereNotExists(function ($query) use ($userId): void {
                $query->selectRaw('1')
                    ->from('notification_reads')
                    ->whereColumn('notification_reads.audit_log_id', 'audit_logs.id')
                    ->where('notification_reads.user_id', $userId);
            })
            ->toBase()
            // Bound parameters rather than values interpolated into the SQL —
            // the datetime formatting is then the driver's job, not a
            // hand-quoted string literal.
            ->selectRaw('audit_logs.id, ?, ?, ?, ?', [$userId, $now, $now, $now]);

        DB::table('notification_reads')->insertUsing(
            ['audit_log_id', 'user_id', 'read_at', 'created_at', 'updated_at'],
            $source,
        );
    }
}
