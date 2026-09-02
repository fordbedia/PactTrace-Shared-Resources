<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Tests;

use PactTrackSDK\SharedResources\Modules\User\Application\Services\UserAuthentication;
use PactTrackSDK\SharedResources\Modules\User\Models\User;
use PactTrackSDK\SharedResources\Modules\Workspace\Models\Workspace;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * Signing in primes the session's `workspace_id` from the user's stored
 * `default_workspace_id`, so a multi-workspace provider is dropped back into
 * whatever they were last using. A missing / stale / cross-tenant /
 * deactivated default clears the key instead and leaves
 * RequestWorkspaceContext to resolve as it did before this feature.
 */
class UserAuthenticationTest extends BaseTest
{
    private TestScenarioCollection $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = ProviderTenantScenario::make('auth-ws');
    }

    private function auth(): UserAuthentication
    {
        return $this->app->make(UserAuthentication::class);
    }

    public function test_login_primes_the_session_from_a_valid_default_workspace(): void
    {
        $owner = $this->tenant['owner'];
        $owner->forceFill(['default_workspace_id' => $this->tenant['workspace']->id])->save();

        $this->auth()->login($owner->fresh());

        $this->assertSame($this->tenant['workspace']->id, session('workspace_id'));
    }

    public function test_login_clears_the_session_key_when_there_is_no_default(): void
    {
        session(['workspace_id' => 999]); // stale value from a previous session

        $owner = $this->tenant['owner'];
        $owner->forceFill(['default_workspace_id' => null])->save();

        $this->auth()->login($owner->fresh());

        $this->assertNull(session('workspace_id'));
    }

    public function test_login_rejects_a_cross_tenant_default(): void
    {
        $other = ProviderTenantScenario::make('auth-ws-other');

        $owner = $this->tenant['owner'];
        $owner->forceFill(['default_workspace_id' => $other['workspace']->id])->save();

        $this->auth()->login($owner->fresh());

        $this->assertNull(session('workspace_id'));
    }

    public function test_login_rejects_a_deactivated_default(): void
    {
        $trashed = Workspace::factory()
            ->forProvider($this->tenant['provider'])
            ->create(['name' => 'auth trashed']);
        $trashed->delete();

        $owner = $this->tenant['owner'];
        $owner->forceFill(['default_workspace_id' => $trashed->id])->save();

        $this->auth()->login($owner->fresh());

        $this->assertNull(session('workspace_id'));
    }

    public function test_attempt_primes_the_session_on_a_successful_sign_in(): void
    {
        $user = User::factory()->create([
            'email' => 'attempt@pacttrack.test',
            'password' => 'correct-horse',
            'provider_id' => $this->tenant['provider']->id,
            'default_workspace_id' => $this->tenant['workspace']->id,
        ]);

        $this->assertTrue($this->auth()->attempt('attempt@pacttrack.test', 'correct-horse'));
        $this->assertSame($this->tenant['workspace']->id, session('workspace_id'));
    }
}
