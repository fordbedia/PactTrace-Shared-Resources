<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Tests;

use PactTrackSDK\SharedResources\Modules\Signature\Domain\Enums\EnvelopeStatus;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Exceptions\EnvelopeCannotTransitionException;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The state machine on the Envelope aggregate itself — see
 * .claude/rules/signature.md and Envelope::assertNotTerminal().
 */
class EnvelopeStatusTransitionTest extends BaseTest
{
    public function test_draft_moves_to_sent_and_stamps_sent_at(): void
    {
        $envelope = $this->envelope(EnvelopeStatus::Draft);

        $envelope->markSent();

        $this->assertSame(EnvelopeStatus::Sent, $envelope->status);
        $this->assertNotNull($envelope->sent_at);
    }

    public function test_re_marking_sent_is_idempotent(): void
    {
        $envelope = $this->envelope(EnvelopeStatus::Sent, ['sent_at' => now()->subDay()]);
        $originalSentAt = $envelope->sent_at;

        $envelope->markSent();

        $this->assertSame(EnvelopeStatus::Sent, $envelope->status);
        $this->assertEquals($originalSentAt, $envelope->sent_at);
    }

    public function test_completed_stamps_completed_at(): void
    {
        $envelope = $this->envelope(EnvelopeStatus::Viewed);

        $envelope->markCompleted();

        $this->assertSame(EnvelopeStatus::Completed, $envelope->status);
        $this->assertNotNull($envelope->completed_at);
    }

    #[DataProvider('terminalStatuses')]
    public function test_a_terminal_envelope_refuses_every_further_transition(EnvelopeStatus $status): void
    {
        $envelope = $this->envelope($status);

        $this->expectException(EnvelopeCannotTransitionException::class);

        $envelope->markSent();
    }

    public function test_marking_declined_locks_the_envelope(): void
    {
        $envelope = $this->envelope(EnvelopeStatus::Viewed);

        $envelope->markDeclined();

        $this->assertSame(EnvelopeStatus::Declined, $envelope->status);

        $this->expectException(EnvelopeCannotTransitionException::class);
        $envelope->markCompleted();
    }

    public function test_marking_voided_locks_the_envelope(): void
    {
        $envelope = $this->envelope(EnvelopeStatus::Sent);

        $envelope->markVoided();

        $this->assertSame(EnvelopeStatus::Voided, $envelope->status);

        $this->expectException(EnvelopeCannotTransitionException::class);
        $envelope->markViewed();
    }

    public function test_marking_expired_locks_the_envelope(): void
    {
        $envelope = $this->envelope(EnvelopeStatus::Sent);

        $envelope->markExpired();

        $this->assertSame(EnvelopeStatus::Expired, $envelope->status);
    }

    /**
     * @return array<string, array{EnvelopeStatus}>
     */
    public static function terminalStatuses(): array
    {
        return array_combine(
            array_map(fn (EnvelopeStatus $s) => $s->value, array_filter(EnvelopeStatus::cases(), fn ($s) => $s->isTerminal())),
            array_map(fn (EnvelopeStatus $s) => [$s], array_filter(EnvelopeStatus::cases(), fn ($s) => $s->isTerminal())),
        );
    }

    private function envelope(EnvelopeStatus $status, array $overrides = []): Envelope
    {
        $tenant = ProviderTenantScenario::make('envelope-fsm');

        return Envelope::factory()->create(array_merge([
            'provider_id' => $tenant['provider']->id,
            'workspace_id' => $tenant['workspace']->id,
            'client_id' => $tenant['client']->id,
            'document_id' => $tenant['document']->id,
            'status' => $status,
        ], $overrides));
    }
}
