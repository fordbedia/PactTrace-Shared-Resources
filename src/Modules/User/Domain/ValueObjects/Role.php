<?php

declare(strict_types=1);

namespace PactTraceSDK\SharedResources\Modules\User\Domain\ValueObjects;

/**
 * The three actor types in PactTrace.
 *
 * Roles are global rather than per-tenant (spatie's `teams` feature is
 * deliberately off): a `users` row carries a single `provider_id`, so a user
 * only ever acts inside one provider and a per-tenant role pivot would add a
 * dimension the schema cannot express anyway. Tenant isolation is enforced by
 * the policies comparing `provider_id`, not by scoping the role itself.
 */
enum Role: string
{
    /** The paying customer — the solo attorney/consultant who owns the tenant. */
    case Owner = 'owner';

    /** Someone working inside the provider's practice, but not the account owner. */
    case Staff = 'staff';

    /** A client of the provider, logging in to their portal. */
    case Client = 'client';

    /**
     * Human-readable name, as used in the build plan and the UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Provider Admin',
            self::Staff => 'Staff',
            self::Client => 'Client',
        };
    }

    /**
     * The permissions granted to this role.
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            // The account owner can do everything within their own tenant.
            self::Owner => Permission::cases(),

            // Staff run the day-to-day engagement but do not administer the
            // tenant itself: no branding, billing, or managing other staff.
            self::Staff => [
                Permission::ProviderView,
                Permission::UserView,

                // Staff work inside a workspace and may rename it, but
                // creating or removing one is an owner's decision — it changes
                // how the practice is organised, not how it is run day to day.
                Permission::WorkspaceView,
                Permission::WorkspaceUpdate,

                Permission::ClientView,
                Permission::ClientCreate,
                Permission::ClientUpdate,
                Permission::ClientInvite,

                Permission::MatterView,
                Permission::MatterCreate,
                Permission::MatterUpdate,
                Permission::MilestoneView,
                Permission::MilestoneCreate,
                Permission::MilestoneUpdate,
                Permission::MilestoneDelete,

                Permission::DocumentView,
                Permission::DocumentUpload,
                Permission::DocumentDownload,
                Permission::DocumentUpdate,
                Permission::FolderView,
                Permission::FolderCreate,
                Permission::FolderUpdate,

                Permission::EnvelopeView,
                Permission::EnvelopeCreate,
                Permission::EnvelopeSend,

                Permission::MessageView,
                Permission::MessageSend,

                Permission::AuditLogView,
            ],

            // Clients see their own engagement and participate in it. They can
            // add documents and sign, but never create or reorganise the
            // provider's structures.
            self::Client => [
                // Their own contact record only — ClientPolicy confines this to
                // the client row they are linked to. Updating it stays with the
                // provider, whose CRM record it ultimately is.
                Permission::ClientView,

                Permission::MatterView,
                Permission::MilestoneView,

                Permission::DocumentView,
                Permission::DocumentUpload,
                Permission::DocumentDownload,
                Permission::FolderView,

                Permission::EnvelopeView,
                Permission::EnvelopeSign,

                Permission::MessageView,
                Permission::MessageSend,
            ],
        };
    }

    /**
     * Role values, for seeding and test assertions.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $role): string => $role->value, self::cases());
    }

    /**
     * Roles that act on behalf of the provider rather than on behalf of a client.
     *
     * @return list<self>
     */
    public static function providerSide(): array
    {
        return [self::Owner, self::Staff];
    }

    public function isProviderSide(): bool
    {
        return in_array($this, self::providerSide(), true);
    }
}
