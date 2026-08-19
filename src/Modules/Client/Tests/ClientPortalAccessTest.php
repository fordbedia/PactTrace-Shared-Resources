<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Client\Tests;

use PactTrackSDK\SharedResources\Modules\User\Models\User;
use PactTrackSDK\SharedResources\Modules\Client\Models\Client;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Role;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * What a client-role user can reach inside their provider's portal.
 *
 * The dangerous case here is not the other tenant — it is the *sibling*: two
 * clients of the same attorney share a `provider_id`, so the tenant check alone
 * passes for both and only the client-level check keeps them apart.
 */
class ClientPortalAccessTest extends BaseTest
{
    private TestScenarioCollection $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = ProviderTenantScenario::make('portal');
    }

    public function test_a_client_sees_their_own_engagement(): void
    {
        $clientUser = $this->tenant['clientUser'];

        $this->assertTrue($clientUser->can('view', $this->tenant['client']));
        $this->assertTrue($clientUser->can('view', $this->tenant['matter']));
        $this->assertTrue($clientUser->can('view', $this->tenant['milestone']));
        $this->assertTrue($clientUser->can('view', $this->tenant['document']));
        $this->assertTrue($clientUser->can('download', $this->tenant['document']));
    }

    public function test_a_client_cannot_see_a_sibling_client_of_the_same_provider(): void
    {
        $clientUser = $this->tenant['clientUser'];

        $this->assertFalse($clientUser->can('view', $this->tenant['otherClient']));
        $this->assertFalse($clientUser->can('view', $this->tenant['otherMatter']));
        $this->assertFalse($clientUser->can('view', $this->tenant['otherDocument']));
        $this->assertFalse($clientUser->can('download', $this->tenant['otherDocument']));
    }

    public function test_a_client_cannot_manage_the_engagement(): void
    {
        $clientUser = $this->tenant['clientUser'];

        $this->assertFalse($clientUser->can('update', $this->tenant['matter']));
        $this->assertFalse($clientUser->can('delete', $this->tenant['matter']));
        $this->assertFalse($clientUser->can('update', $this->tenant['milestone']));
        $this->assertFalse($clientUser->can('delete', $this->tenant['document']));
        $this->assertFalse($clientUser->can('update', $this->tenant['client']));
        $this->assertFalse($clientUser->can('create', [Client::class]));
    }

    public function test_a_client_cannot_administer_the_provider(): void
    {
        $clientUser = $this->tenant['clientUser'];

        $this->assertFalse($clientUser->can('view', $this->tenant['provider']));
        $this->assertFalse($clientUser->can('update', $this->tenant['provider']));
        $this->assertFalse($clientUser->can('manageBilling', $this->tenant['provider']));
    }

    public function test_a_client_cannot_read_the_audit_trail(): void
    {
        $this->assertFalse($this->tenant['clientUser']->can('viewAny', \PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog::class));
    }

    public function test_provider_internal_documents_are_hidden_from_clients(): void
    {
        // A document with no client_id is the provider's own work product —
        // drafts, internal notes. It lives in the tenant but belongs to no
        // client, and must not surface in anybody's portal.
        $internal = Document::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'client_id' => null,
            'matter_id' => null,
            'uploaded_by' => $this->tenant['owner']->id,
        ]);

        $this->assertFalse($this->tenant['clientUser']->can('view', $internal));
        $this->assertFalse($this->tenant['clientUser']->can('download', $internal));

        // The provider side still sees it.
        $this->assertTrue($this->tenant['owner']->can('view', $internal));
        $this->assertTrue($this->tenant['staff']->can('view', $internal));
    }

    public function test_a_client_user_with_no_client_record_is_denied(): void
    {
        // Mid-invitation: the login exists and carries the client role, but is
        // not linked to a Client row yet. It must not inherit the whole tenant.
        $dangling = User::factory()->create([
            'email' => 'dangling@pacttrack.test',
            'provider_id' => $this->tenant['provider']->id,
        ]);
        $dangling->assignRole(Role::Client->value);
        $dangling = $dangling->fresh();

        $this->assertFalse($dangling->can('view', $this->tenant['client']));
        $this->assertFalse($dangling->can('view', $this->tenant['matter']));
        $this->assertFalse($dangling->can('view', $this->tenant['document']));
    }
}
