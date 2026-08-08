<?php

declare(strict_types=1);

namespace PactTraceSDK\SharedResources\Modules\Document\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use PactTraceSDK\SharedResources\Modules\Document\Models\Document;
use PactTraceSDK\SharedResources\Modules\User\Application\Authorization\TenantScopedPolicy;
use PactTraceSDK\SharedResources\Modules\User\Domain\ValueObjects\Permission;

class DocumentPolicy extends TenantScopedPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->check($user, Permission::DocumentView);
    }

    public function view(User $user, Document $document): bool
    {
        return $this->check($user, Permission::DocumentView, $document);
    }

    /**
     * Pass whatever the upload is being filed under — a Client, Matter or
     * Folder — so the destination is tenant-checked:
     *
     *     $user->can('create', [Document::class, $matter])
     */
    public function create(User $user, ?Model $destination = null): bool
    {
        return $this->check($user, Permission::DocumentUpload, $destination);
    }

    /**
     * Separate from `view` on purpose: listing a document in the portal and
     * pulling its bytes out of S3 are different acts, and the audit trail is
     * expected to distinguish them.
     */
    public function download(User $user, Document $document): bool
    {
        return $this->check($user, Permission::DocumentDownload, $document);
    }

    public function update(User $user, Document $document): bool
    {
        return $this->check($user, Permission::DocumentUpdate, $document);
    }

    public function delete(User $user, Document $document): bool
    {
        return $this->check($user, Permission::DocumentDelete, $document);
    }
}
