<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Messaging\Tests;

use Illuminate\Support\Facades\Gate;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\MessageThread;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * The view-vs-reply split on a thread (see .claude/rules/messaging.md):
 *
 *  - any staffer in the provider may VIEW any thread (continuity);
 *  - only the thread's own staff_user_id may REPLY from the provider side;
 *  - the thread's client may both view and reply into their own thread;
 *  - nothing crosses the provider boundary.
 *
 * The broadcast channel authorizer (backend/routes/channels.php) calls the
 * SAME `view` method, so these outcomes govern subscription too.
 */
class MessageThreadPolicyTest extends BaseTest
{
    private TestScenarioCollection $tenant;

    private TestScenarioCollection $other;

    private MessageThread $thread;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = ProviderTenantScenario::make('policy-a');
        $this->other = ProviderTenantScenario::make('policy-b');

        // A thread on the tenant's matter, with the OWNER as its staff party.
        $this->thread = MessageThread::factory()
            ->forMatter($this->tenant['matter'], $this->tenant['owner'])
            ->create();
    }

    public function test_the_threads_staff_member_can_view_and_reply(): void
    {
        $gate = Gate::forUser($this->tenant['owner']);

        $this->assertTrue($gate->allows('view', $this->thread));
        $this->assertTrue($gate->allows('reply', $this->thread));
    }

    public function test_another_staffer_in_the_provider_can_view_but_not_reply(): void
    {
        $gate = Gate::forUser($this->tenant['staff']);

        $this->assertTrue($gate->allows('view', $this->thread), 'continuity — any staffer can read');
        $this->assertFalse($gate->allows('reply', $this->thread), 'only the thread staff_user_id may reply');
    }

    public function test_the_threads_client_can_view_and_reply_into_their_own_thread(): void
    {
        $gate = Gate::forUser($this->tenant['clientUser']);

        $this->assertTrue($gate->allows('view', $this->thread));
        $this->assertTrue($gate->allows('reply', $this->thread));
    }

    public function test_a_user_from_another_provider_can_neither_view_nor_reply(): void
    {
        $gate = Gate::forUser($this->other['owner']);

        $this->assertFalse($gate->allows('view', $this->thread));
        $this->assertFalse($gate->allows('reply', $this->thread));
    }
}
