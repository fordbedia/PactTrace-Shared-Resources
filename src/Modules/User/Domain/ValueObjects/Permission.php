<?php

declare(strict_types=1);

namespace PactTraceSDK\SharedResources\Modules\User\Domain\ValueObjects;

/**
 * The full permission catalogue for PactTrace.
 *
 * A permission answers "is this actor allowed to perform this kind of action at
 * all?" — never "on which record?". Record-level scoping (does this row belong
 * to the actor's provider / to this client?) is the job of the policies in each
 * module, because it needs the record itself. Keep that split: a permission that
 * encodes ownership would have to be re-issued per row.
 *
 * Framework-free by design (hexagonal rule in CLAUDE.md) — the spatie tables are
 * an adapter that stores these values, not the definition of them.
 */
enum Permission: string
{
    // Provider (tenant) settings and branding.
    case ProviderView = 'provider.view';
    case ProviderUpdate = 'provider.update';
    case ProviderManageBranding = 'provider.manage-branding';
    case ProviderManageBilling = 'provider.manage-billing';

    // Staff/user administration within a provider.
    case UserView = 'user.view';
    case UserInvite = 'user.invite';
    case UserUpdate = 'user.update';
    case UserDelete = 'user.delete';

    // Clients (the CRM-style record, not the login).
    case ClientView = 'client.view';
    case ClientCreate = 'client.create';
    case ClientUpdate = 'client.update';
    case ClientDelete = 'client.delete';
    case ClientInvite = 'client.invite';

    // Workspaces — the branded space and its client-facing vocabulary.
    // Available on every plan; nothing here is gated by subscription tier.
    case WorkspaceView = 'workspace.view';
    case WorkspaceCreate = 'workspace.create';
    case WorkspaceUpdate = 'workspace.update';
    case WorkspaceDelete = 'workspace.delete';

    // Matters and milestones.
    case MatterView = 'matter.view';
    case MatterCreate = 'matter.create';
    case MatterUpdate = 'matter.update';
    case MatterDelete = 'matter.delete';
    case MilestoneView = 'milestone.view';
    case MilestoneCreate = 'milestone.create';
    case MilestoneUpdate = 'milestone.update';
    case MilestoneDelete = 'milestone.delete';

    // Documents and folders.
    case DocumentView = 'document.view';
    case DocumentUpload = 'document.upload';
    case DocumentDownload = 'document.download';
    case DocumentUpdate = 'document.update';
    case DocumentDelete = 'document.delete';
    case FolderView = 'folder.view';
    case FolderCreate = 'folder.create';
    case FolderUpdate = 'folder.update';
    case FolderDelete = 'folder.delete';

    // E-signature envelopes.
    case EnvelopeView = 'envelope.view';
    case EnvelopeCreate = 'envelope.create';
    case EnvelopeSend = 'envelope.send';
    case EnvelopeSign = 'envelope.sign';
    case EnvelopeVoid = 'envelope.void';

    // In-portal messaging.
    case MessageView = 'message.view';
    case MessageSend = 'message.send';

    // Compliance.
    case AuditLogView = 'audit-log.view';

    /**
     * Every permission value, for seeding and for test assertions.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $permission): string => $permission->value, self::cases());
    }
}
