<?php

declare(strict_types=1);

namespace PactTraceSDK\SharedResources\Modules\Workspace\Application\Labels;

use InvalidArgumentException;
use PactTraceSDK\SharedResources\Modules\Workspace\Domain\Ports\CurrentWorkspace;
use PactTraceSDK\SharedResources\Modules\Workspace\Domain\Ports\WorkspacePresets;
use PactTraceSDK\SharedResources\Modules\Workspace\Domain\ValueObjects\WorkspaceLabels;
use PactTraceSDK\SharedResources\Modules\Workspace\Domain\ValueObjects\WorkspaceType;
use PactTraceSDK\SharedResources\Modules\Workspace\Models\Workspace;

/**
 * Turns "what does this workspace call an engagement?" into a string.
 *
 * The single place wording is resolved: workspace_label(), the
 * `@workspaceLabel` Blade directive and any future API resource all come
 * through here, so they cannot disagree.
 *
 * Caches the resolved labels per workspace id, because a portal page asks for
 * these once per heading and each miss would otherwise be a query.
 */
final class WorkspaceLabelResolver
{
    public const CLIENT = 'client';

    public const ENGAGEMENT = 'engagement';

    /** @var array<int|string, WorkspaceLabels> */
    private array $cache = [];

    public function __construct(
        private readonly CurrentWorkspace $currentWorkspace,
        private readonly WorkspacePresets $presets,
    ) {
    }

    /**
     * @param  'client'|'engagement'  $key
     */
    public function label(string $key): string
    {
        $labels = $this->labels();

        return match ($key) {
            self::CLIENT => $labels->client,
            self::ENGAGEMENT => $labels->engagement,
            default => throw new InvalidArgumentException(
                sprintf(
                    'Unknown workspace label [%s]; expected "%s" or "%s".',
                    $key,
                    self::CLIENT,
                    self::ENGAGEMENT,
                )
            ),
        };
    }

    /**
     * The current workspace's labels, or the general preset when there is no
     * workspace in context.
     *
     * Falling back rather than failing is deliberate: wording is presentation,
     * and a marketing page or a signed-out screen has no workspace but still
     * has headings to render.
     */
    public function labels(): WorkspaceLabels
    {
        $workspaceId = $this->currentWorkspace->id();

        if ($workspaceId === null) {
            return $this->presets->for(WorkspaceType::default());
        }

        return $this->cache[$workspaceId] ??= $this->lookUp($workspaceId);
    }

    /**
     * Drop cached labels. Needed after a workspace is renamed, or the active
     * workspace switched, within one process — chiefly tests and queue workers.
     */
    public function flush(): void
    {
        $this->cache = [];
    }

    private function lookUp(int $workspaceId): WorkspaceLabels
    {
        $workspace = Workspace::query()->find($workspaceId);

        return $workspace instanceof Workspace
            ? $workspace->labels()
            : $this->presets->for(WorkspaceType::default());
    }
}
