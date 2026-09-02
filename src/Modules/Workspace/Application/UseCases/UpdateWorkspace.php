<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Workspace\Application\UseCases;

use PactTrackSDK\SharedResources\Modules\Workspace\Application\Repository\Ports\WorkspaceRepository;
use PactTrackSDK\SharedResources\Modules\Workspace\Domain\Ports\WorkspacePresets;
use PactTrackSDK\SharedResources\Modules\Workspace\Domain\ValueObjects\WorkspaceType;
use PactTrackSDK\SharedResources\Modules\Workspace\Models\Workspace;

/**
 * Edit an existing workspace's name / type / labels.
 *
 * The onboarding flow's "finish setting up my sole workspace" branch calls
 * this (that workspace already exists — RegisterProvider created it — so a
 * second create would be wrong), and it is also the backend for a general
 * workspace-settings edit later.
 *
 * `Workspace::creating()` fills blank labels from the type's preset, but that
 * hook does not fire on update, so this does the equivalent by hand: when a
 * label arrives blank it is refilled from the (possibly newly chosen) type's
 * preset rather than persisted as an empty string — WorkspaceLabels rejects a
 * blank label reaching the portal, and "picked Legal, left the label field
 * empty" should mean "use Legal's preset", not "no label".
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
        string $workspaceType,
        ?string $clientLabel = null,
        ?string $engagementLabel = null,
    ): Workspace {
        $type = WorkspaceType::tryFrom($workspaceType) ?? WorkspaceType::default();
        $preset = $this->presets->for($type);

        return $this->workspaces->saveAttributes($workspace, [
            'name' => $name,
            'workspace_type' => $type->value,
            'client_label' => $this->blankToNull($clientLabel) ?? $preset->client,
            'engagement_label' => $this->blankToNull($engagementLabel) ?? $preset->engagement,
        ]);
    }

    private function blankToNull(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
