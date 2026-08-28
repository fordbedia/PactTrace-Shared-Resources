<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Messaging\Application\Action;

use Illuminate\Support\Collection;
use PactTrackSDK\SharedResources\Modules\Messaging\Application\Port\Query\ProviderStaffDirectory;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * The list of staff a portal client can start a conversation with — backs
 * GET /api/v1/portal/matters/{matter}/staff-directory. Thin over the
 * ProviderStaffDirectory port; the provider is resolved by the controller
 * from the authenticated client's own account, never from client input.
 *
 * @return Collection<int, User>
 */
class GetProviderStaffDirectoryAction
{
    public function __construct(private readonly ProviderStaffDirectory $directory)
    {
    }

    /**
     * @return Collection<int, User>
     */
    public function handle(int $providerId): Collection
    {
        return $this->directory->forProvider($providerId);
    }
}
