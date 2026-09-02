<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Workspace\Database\Factories;

use PactTrackSDK\SharedResources\Modules\User\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use PactTrackSDK\SharedResources\Modules\User\Models\Provider;
use PactTrackSDK\SharedResources\Modules\Workspace\Domain\ValueObjects\WorkspaceType;
use PactTrackSDK\SharedResources\Modules\Workspace\Models\Workspace;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<Workspace>
 */
class WorkspaceFactory extends Factory
{
    protected $model = Workspace::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider_id' => Provider::factory(),
            'owner_id' => User::factory(),
            // Unique because (provider_id, name) is a unique key, and a test
            // building several workspaces for one provider is the normal case
            // rather than the exception.
            'name' => fake()->unique()->company(),
            'workspace_type' => WorkspaceType::default(),
            // Left null so the model's creating hook applies the preset — the
            // factory should exercise that path, not bypass it.
            'client_label' => null,
            'engagement_label' => null,
        ];
    }

    /**
     * The provider's protected primary workspace — the one RegisterProvider
     * stamps at sign-up. Tests use this to exercise the "can't deactivate the
     * primary" guard; production only ever sets it in RegisterProvider.
     */
    public function primary(): self
    {
        return $this->state(fn (): array => ['is_primary' => true]);
    }

    /**
     * A workspace of a given type, taking that type's preset labels.
     */
    public function ofType(WorkspaceType $type): self
    {
        return $this->state(fn (): array => [
            'workspace_type' => $type,
        ]);
    }

    /**
     * A workspace whose labels have been customised away from the preset.
     */
    public function withLabels(?string $client = null, ?string $engagement = null): self
    {
        return $this->state(fn (): array => array_filter([
            'client_label' => $client,
            'engagement_label' => $engagement,
        ], static fn (?string $label): bool => $label !== null));
    }

    /**
     * Attach the workspace to an existing provider, and default its owner to
     * that provider's owner rather than minting an unrelated user.
     */
    public function forProvider(Provider $provider): self
    {
        return $this->state(fn (): array => [
            'provider_id' => $provider->id,
            'owner_id' => $provider->owner_user_id,
        ]);
    }
}
