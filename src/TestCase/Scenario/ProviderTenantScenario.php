<?php

declare(strict_types=1);

namespace PactTraceSDK\SharedResources\TestCase\Scenario;

use PactTraceSDK\SharedResources\Modules\User\Models\User;
use PactTraceSDK\SharedResources\Modules\Client\Models\Client;
use PactTraceSDK\SharedResources\Modules\Document\Models\Document;
use PactTraceSDK\SharedResources\Modules\Matter\Models\Milestone;
use PactTraceSDK\SharedResources\Modules\Matter\Models\Matter;
use PactTraceSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTraceSDK\SharedResources\Modules\User\Domain\ValueObjects\Role;
use PactTraceSDK\SharedResources\Modules\User\Models\Provider;
use PactTraceSDK\SharedResources\Modules\Workspace\Models\Workspace;

/**
 * One fully populated provider tenant: an owner, a staff member, one workspace,
 * two clients (one of them with a portal login), and a matter, milestone,
 * document and envelope belonging to each client.
 *
 * Two clients rather than one is the point — most authorisation bugs are not
 * "the wrong provider got in" but "the right provider's *other* client got in",
 * and a fixture with a single client cannot catch that. Build two of these
 * scenarios to test isolation across tenants as well.
 *
 * Every workspace-scoped record is created with an explicit `workspace_id`
 * rather than leaning on BelongsToWorkspace's creating hook. Tests run with no
 * authenticated user and therefore no workspace context, so the hook would
 * leave these null — and a fixture whose rows are invisible to every scoped
 * query is not a fixture. Tests that exercise the hook itself should set the
 * context and omit the id deliberately.
 *
 * Keys: provider, owner, staff, workspace, client, clientUser, matter,
 * milestone, document, envelope, otherClient, otherMatter, otherDocument.
 */
class ProviderTenantScenario extends BaseScenario
{
    public function __construct(private readonly string $prefix = 'tenant')
    {
    }

    public function handle(): TestScenarioCollection
    {
        // Owner before provider, so the factory does not mint a throwaway user
        // for `owner_user_id` that we would immediately replace.
        $owner = User::factory()->create(['email' => "{$this->prefix}-owner@pacttrace.test"]);
        $provider = Provider::factory()->create(['owner_user_id' => $owner->id]);

        $owner->forceFill(['provider_id' => $provider->id])->save();
        $owner->assignRole(Role::Owner->value);

        $staff = $this->userFor($provider, 'staff', Role::Staff);

        $workspace = Workspace::factory()
            ->forProvider($provider)
            ->create(['name' => "{$this->prefix} workspace"]);

        $client = Client::factory()->create(['provider_id' => $provider->id]);
        $clientUser = $this->userFor($provider, 'client', Role::Client);
        $client->forceFill(['user_id' => $clientUser->id])->save();

        $matter = Matter::factory()->create([
            'provider_id' => $provider->id,
            'workspace_id' => $workspace->id,
            'client_id' => $client->id,
        ]);

        $milestone = Milestone::factory()->create(['matter_id' => $matter->id]);

        $document = Document::factory()->create([
            'provider_id' => $provider->id,
            'workspace_id' => $workspace->id,
            'client_id' => $client->id,
            'matter_id' => $matter->id,
            'uploaded_by' => $owner->id,
        ]);

        $envelope = Envelope::factory()->create([
            'provider_id' => $provider->id,
            'workspace_id' => $workspace->id,
            'client_id' => $client->id,
            'document_id' => $document->id,
        ]);

        // A second client of the same provider, with no portal login of its
        // own — the client user above must never reach any of this.
        $otherClient = Client::factory()->create(['provider_id' => $provider->id]);

        $otherMatter = Matter::factory()->create([
            'provider_id' => $provider->id,
            'workspace_id' => $workspace->id,
            'client_id' => $otherClient->id,
        ]);

        $otherDocument = Document::factory()->create([
            'provider_id' => $provider->id,
            'workspace_id' => $workspace->id,
            'client_id' => $otherClient->id,
            'matter_id' => $otherMatter->id,
            'uploaded_by' => $owner->id,
        ]);

        return $this->collect([
            'provider' => $provider->refresh(),
            'owner' => $owner->fresh(),
            'staff' => $staff->fresh(),
            'workspace' => $workspace,
            'client' => $client->refresh(),
            'clientUser' => $clientUser->fresh(),
            'matter' => $matter,
            'milestone' => $milestone,
            'document' => $document,
            'envelope' => $envelope,
            'otherClient' => $otherClient,
            'otherMatter' => $otherMatter,
            'otherDocument' => $otherDocument,
        ]);
    }

    private function userFor(Provider $provider, string $slug, Role $role): User
    {
        $user = User::factory()->create([
            'email' => "{$this->prefix}-{$slug}@pacttrace.test",
            'provider_id' => $provider->id,
        ]);

        $user->assignRole($role->value);

        return $user;
    }
}
