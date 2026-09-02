<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Workspace\Application\UseCases;

use PactTrackSDK\SharedResources\Modules\Workspace\Application\Repository\Ports\WorkspaceRepository;
use PactTrackSDK\SharedResources\Modules\Workspace\Domain\Ports\WorkspacePresets;
use PactTrackSDK\SharedResources\Modules\Workspace\Domain\ValueObjects\WorkspaceType;
use PactTrackSDK\SharedResources\Modules\Workspace\Models\Workspace;

/**
 * Edit an existing workspace's name and labels.
 *
 * Two callers:
 *   • the `/workspaces` Edit modal — name + labels only; the type tile is
 *     rendered locked and the field is not sent.
 *   • the sign-up funnel's "Step 2 of 2" onboarding screen
 *     (`/dashboard/create-workspace?onboarding=1`) — finishes the workspace
 *     `RegisterProvider` already created (a POST would mint a duplicate).
 *
 * ── Workspace type is immutable once chosen. ────────────────────────────
 * The design ("Type can't be changed after creation") is now enforced here,
 * not just hidden in the UI. The single, deliberate exception is the first
 * move OFF the `general` placeholder `RegisterProvider` stamps at sign-up:
 * that is the onboarding screen choosing a practice type for the first time,
 * which is configuration, not a change. A workspace that already carries a
 * specialised type — or that is being moved back to `general`, or between two
 * specialised types — keeps its stored type, and a differing `workspace_type`
 * on the request is IGNORED, not rejected (the "trust the resolved record,
 * not the payload" rule the Document module applies to `client_id`).
 *
 * `Workspace::creating()` fills blank labels from the type's preset, but that
 * hook does not fire on update, so this does the equivalent by hand: a label
 * that arrives blank is refilled from the effective type's preset rather than
 * persisted as an empty string.
 */
final class UpdateWorkspace
{
    public function __construct(
        private readonly WorkspaceRepository $workspaces,
        private readonly WorkspacePresets $presets,
    ) {
    }

    public function handle(
        Workspace $workspace,
        string $name,
        ?string $requestedType = null,
        ?string $clientLabel = null,
        ?string $engagementLabel = null,
    ): Workspace {
        $type = $this->resolveType($workspace, $requestedType);
        $preset = $this->presets->for($type);

        return $this->workspaces->saveAttributes($workspace, [
            'name' => $name,
            'workspace_type' => $type->value,
            'client_label' => $this->blankToNull($clientLabel) ?? $preset->client,
            'engagement_label' => $this->blankToNull($engagementLabel) ?? $preset->engagement,
        ]);
    }

    private function resolveType(Workspace $workspace, ?string $requestedType): WorkspaceType
    {
        $current = $workspace->workspace_type instanceof WorkspaceType
            ? $workspace->workspace_type
            : WorkspaceType::tryFrom((string) $workspace->workspace_type) ?? WorkspaceType::default();

        $requested = $requestedType !== null ? WorkspaceType::tryFrom($requestedType) : null;

        // Only the one-time "leave the placeholder" transition is allowed.
        if ($current === WorkspaceType::General
            && $requested !== null
            && $requested !== WorkspaceType::General
        ) {
            return $requested;
        }

        return $current;
    }

    private function blankToNull(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
