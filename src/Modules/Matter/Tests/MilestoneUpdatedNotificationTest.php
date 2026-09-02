<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Matter\Tests;

use Illuminate\Support\Facades\Mail;
use PactTrackSDK\SharedResources\Modules\Matter\Application\Action\UpdateMattersHandler;
use PactTrackSDK\SharedResources\Modules\Matter\Application\DTO\MattersData;
use PactTrackSDK\SharedResources\Modules\Matter\Application\Services\MilestoneProgressionService;
use PactTrackSDK\SharedResources\Modules\Matter\Domain\ValueObjects\DefaultMilestone;
use PactTrackSDK\SharedResources\Modules\Matter\Models\Milestone;
use PactTrackSDK\SharedResources\Modules\Notification\Mail\MilestoneUpdatedEmail;
use PactTrackSDK\SharedResources\Modules\Notification\Support\Notification;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * Dispatch-site gating for `milestone_updated` (default OFF) — see
 * .claude/rules/notification.md, "Notification::isset() gating at dispatch
 * sites". Both trigger paths are covered: automatic milestone completion via
 * MilestoneProgressionService, and a manual matter-status edit via
 * UpdateMattersHandler. Both go through MilestoneNotifier and email the
 * matter's assigned staff member (or the owner as fallback) only when that
 * recipient has the notification on.
 */
class MilestoneUpdatedNotificationTest extends BaseTest
{
    private TestScenarioCollection $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->tenant = ProviderTenantScenario::make('milestone-notify');
    }

    private function pendingMilestone(string $name): Milestone
    {
        return Milestone::factory()->create([
            'matter_id' => $this->tenant['matter']->id,
            'name' => $name,
            'status' => 'pending',
            'completed_at' => null,
        ]);
    }

    /* ── automatic milestone completion ─────────────────────────────── */

    public function test_advancing_a_milestone_emails_the_contact_when_they_have_it_on(): void
    {
        Notification::enable('milestone_updated', $this->tenant['owner']);
        $this->pendingMilestone(DefaultMilestone::DRAFTING);

        app(MilestoneProgressionService::class)->completeMilestone(
            (int) $this->tenant['matter']->id,
            DefaultMilestone::DRAFTING,
        );

        Mail::assertQueued(
            MilestoneUpdatedEmail::class,
            fn (MilestoneUpdatedEmail $mail): bool =>
                $mail->hasTo($this->tenant['owner']->email)
                && $mail->matterName === $this->tenant['matter']->name
                && $mail->workspaceName === $this->tenant['workspace']->name
                && str_contains($mail->render(), $this->tenant['workspace']->name),
        );
    }

    public function test_advancing_a_milestone_sends_nothing_by_default(): void
    {
        $this->pendingMilestone(DefaultMilestone::DRAFTING);

        app(MilestoneProgressionService::class)->completeMilestone(
            (int) $this->tenant['matter']->id,
            DefaultMilestone::DRAFTING,
        );

        Mail::assertNotQueued(MilestoneUpdatedEmail::class);
    }

    public function test_a_noop_completion_call_does_not_email(): void
    {
        Notification::enable('milestone_updated', $this->tenant['owner']);
        Milestone::factory()->create([
            'matter_id' => $this->tenant['matter']->id,
            'name' => DefaultMilestone::DRAFTING,
            'status' => 'completed',
            'completed_at' => now()->subDay(),
        ]);

        app(MilestoneProgressionService::class)->completeMilestone(
            (int) $this->tenant['matter']->id,
            DefaultMilestone::DRAFTING,
        );

        Mail::assertNotQueued(MilestoneUpdatedEmail::class);
    }

    /* ── manual matter-status edit ──────────────────────────────────── */

    public function test_a_matter_status_change_emails_the_contact_when_they_have_it_on(): void
    {
        Notification::enable('milestone_updated', $this->tenant['owner']);
        $this->tenant['matter']->forceFill(['status' => 'active'])->save();

        app(UpdateMattersHandler::class)->handle(
            $this->tenant['matter'],
            MattersData::fromMatter($this->tenant['matter'], ['status' => 'completed']),
        );

        Mail::assertQueued(
            MilestoneUpdatedEmail::class,
            fn (MilestoneUpdatedEmail $mail): bool => $mail->hasTo($this->tenant['owner']->email),
        );
    }

    public function test_a_matter_edit_that_does_not_change_status_emails_nothing(): void
    {
        Notification::enable('milestone_updated', $this->tenant['owner']);
        $this->tenant['matter']->forceFill(['status' => 'active'])->save();

        app(UpdateMattersHandler::class)->handle(
            $this->tenant['matter'],
            MattersData::fromMatter($this->tenant['matter'], ['name' => 'Renamed matter']),
        );

        Mail::assertNotQueued(MilestoneUpdatedEmail::class);
    }

    public function test_a_matter_status_change_sends_nothing_when_the_contact_has_it_off(): void
    {
        $this->tenant['matter']->forceFill(['status' => 'active'])->save();

        app(UpdateMattersHandler::class)->handle(
            $this->tenant['matter'],
            MattersData::fromMatter($this->tenant['matter'], ['status' => 'completed']),
        );

        Mail::assertNotQueued(MilestoneUpdatedEmail::class);
    }
}
