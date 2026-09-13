<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Client\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use PactTrackSDK\SharedResources\Modules\Client\Domain\ValueObjects\ClientOverviewSummary;

/**
 * Wire shape for the Client Detail page's Overview tab
 * (`GET /clients/{client}/overview`) — a thin pass-through of
 * {@see ClientOverviewSummary::toArray()}, same convention as every other
 * Resource in this module.
 *
 * @mixin ClientOverviewSummary
 */
class ClientOverviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ClientOverviewSummary $summary */
        $summary = $this->resource;

        return $summary->toArray();
    }
}
