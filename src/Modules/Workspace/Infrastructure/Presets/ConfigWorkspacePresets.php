<?php

declare(strict_types=1);

namespace PactTraceSDK\SharedResources\Modules\Workspace\Infrastructure\Presets;

use Illuminate\Contracts\Config\Repository as Config;
use PactTraceSDK\SharedResources\Modules\Workspace\Domain\Ports\WorkspacePresets;
use PactTraceSDK\SharedResources\Modules\Workspace\Domain\ValueObjects\WorkspaceLabels;
use PactTraceSDK\SharedResources\Modules\Workspace\Domain\ValueObjects\WorkspaceType;

/**
 * Reads presets from `config('workspace.presets')`.
 *
 * The adapter side of WorkspacePresets: this is the only class in the module
 * that knows presets are a config file at all.
 *
 * It resolves defensively, because config is editable by hand and a workspace
 * with no words to describe itself would break the client portal outright.
 * Each lookup falls back type preset -> general preset -> hardcoded floor.
 */
final class ConfigWorkspacePresets implements WorkspacePresets
{
    /**
     * Last-resort labels, used only when config has been emptied or broken.
     * Deliberately the same wording as the shipped `general` preset.
     */
    private const FLOOR_CLIENT_LABEL = 'Your Provider';

    private const FLOOR_ENGAGEMENT_LABEL = 'Project';

    public function __construct(private readonly Config $config)
    {
    }

    public function for(WorkspaceType $type): WorkspaceLabels
    {
        $floor = new WorkspaceLabels(self::FLOOR_CLIENT_LABEL, self::FLOOR_ENGAGEMENT_LABEL);

        $general = WorkspaceLabels::fromArray($this->entry(WorkspaceType::General), $floor);

        if ($type === WorkspaceType::General) {
            return $general;
        }

        return WorkspaceLabels::fromArray($this->entry($type), $general);
    }

    public function all(): array
    {
        $presets = [];

        foreach (WorkspaceType::cases() as $type) {
            $presets[$type->value] = $this->for($type);
        }

        return $presets;
    }

    /**
     * The raw config entry for a type, normalised to an array so a malformed
     * value (a string, null, a missing key) becomes "no override" rather than
     * a TypeError deep inside the value object.
     *
     * @return array{client?: string|null, engagement?: string|null}
     */
    private function entry(WorkspaceType $type): array
    {
        $entry = $this->config->get("workspace.presets.{$type->value}");

        return is_array($entry) ? $entry : [];
    }
}
