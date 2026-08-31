<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Dashboard\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use PactTrackSDK\SharedResources\Modules\Document\Infrastructure\Services\ByteFormatter;
use PactTrackSDK\SharedResources\Modules\Matter\Http\Resources\MatterResource;

/**
 * Shapes a DashboardSummary for `GET /api/v1/dashboard/summary`.
 *
 * Byte counts are worded server-side via ByteFormatter — the same reason
 * DocumentController::storage() does it: "6.2 GB" / "10 GB" reads
 * identically everywhere and the frontend never re-implements the units.
 * `matters_in_progress` reuses MatterResource verbatim (so `progress` is the
 * one MatterProgressCalculator value, not a second calculation).
 *
 * @mixin \PactTrackSDK\SharedResources\Modules\Dashboard\Application\DTO\DashboardSummary
 */
final class DashboardSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $bytes = app(ByteFormatter::class);
        $storage = $this->storage;

        return [
            'stats' => [
                'active_matters' => [
                    'value' => $this->activeMatters,
                    'new_this_week' => $this->mattersCreatedThisWeek,
                ],
                'docs_awaiting' => [
                    'value' => $this->docsAwaiting,
                ],
                'clients' => [
                    'value' => $this->clients,
                    'new_this_month' => $this->clientsCreatedThisMonth,
                ],
                'signed_this_month' => [
                    'value' => $this->signedThisMonth,
                    'previous_month' => $this->signedPreviousMonth,
                    // Null when last month had zero completions — a percentage
                    // change from a zero base has no meaning, so the frontend
                    // shows a plain count instead of a fake arrow.
                    'change_pct' => $this->signedPreviousMonth > 0
                        ? round(($this->signedThisMonth - $this->signedPreviousMonth) / $this->signedPreviousMonth * 100, 1)
                        : null,
                ],
            ],
            'storage' => [
                'used_bytes' => $storage->usedBytes,
                'limit_bytes' => $storage->limitBytes,
                'remaining_bytes' => $storage->remainingBytes(),
                'percentage' => $storage->percentage(),
                'over_limit' => $storage->isOverLimit(),
                'used_label' => $bytes->format($storage->usedBytes),
                'limit_label' => $bytes->format($storage->limitBytes),
            ],
            'signatures_last_7_days' => $this->signaturesLast7Days,
            'matters_in_progress' => MatterResource::collection($this->mattersInProgress),
        ];
    }
}
