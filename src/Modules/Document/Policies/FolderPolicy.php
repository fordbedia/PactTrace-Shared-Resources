<?php

declare(strict_types=1);

namespace PactTraceSDK\SharedResources\Modules\Document\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use PactTraceSDK\SharedResources\Modules\Document\Models\Folder;
use PactTraceSDK\SharedResources\Modules\User\Application\Authorization\TenantScopedPolicy;
use PactTraceSDK\SharedResources\Modules\User\Domain\ValueObjects\Permission;

class FolderPolicy extends TenantScopedPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->check($user, Permission::FolderView);
    }

    public function view(User $user, Folder $folder): bool
    {
        return $this->check($user, Permission::FolderView, $folder);
    }

    /**
     * Pass the parent the folder is being created under (a Folder, Client or
     * Matter) so the destination is tenant-checked.
     */
    public function create(User $user, ?Model $parent = null): bool
    {
        return $this->check($user, Permission::FolderCreate, $parent);
    }

    public function update(User $user, Folder $folder): bool
    {
        return $this->check($user, Permission::FolderUpdate, $folder);
    }

    public function delete(User $user, Folder $folder): bool
    {
        return $this->check($user, Permission::FolderDelete, $folder);
    }
}
