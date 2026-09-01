<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Infrastructure\Repositories\Eloquent;

use PactTrackSDK\SharedResources\Modules\Matter\Models\Matter;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\DepartingStaffReassignment;

class EloquentDepartingStaffReassignment implements DepartingStaffReassignment
{
    public function clearMatterAssignments(int $userId): int
    {
        // acrossWorkspaces(): a deactivation must reach every matter the user
        // is on, not just those in the request's current workspace context.
        return Matter::query()
            ->acrossWorkspaces()
            ->where('assigned_staff_id', $userId)
            ->update(['assigned_staff_id' => null]);
    }
}
