<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Workspace\Domain\Ports;

use PactTrackSDK\SharedResources\Modules\Workspace\Domain\ValueObjects\WorkspaceLabels;
use PactTrackSDK\SharedResources\Modules\Workspace\Domain\ValueObjects\WorkspaceType;

/**
 * Where the starting labels for a workspace type come from.
 *
 * A port rather than a static lookup on the enum, because presets are meant to
 * be *editable deployment data* — a firm that says "Counsel" instead of
 * "Attorney" should be able to change the default for every new workspace
 * without a code change. The shipped adapter reads a config file; a future one
 * could read the database.
 *
 * Presets are only ever a starting point. Once a workspace exists, its own
 * `client_label` / `engagement_label` columns win — see Workspace::labels().
 */
interface WorkspacePresets
{
    /**
     * The labels a newly created workspace of this type starts with.
     *
     * Must never fail: an unknown or missing preset falls back to the general
     * one, so a half-configured config file cannot leave a workspace with no
     * words to describe itself.
     */
    public function for(WorkspaceType $type): WorkspaceLabels;

    /**
     * Every preset, keyed by workspace type value — for settings screens that
     * show the choices before one is picked.
     *
     * @return array<string, WorkspaceLabels>
     */
    public function all(): array;
}
