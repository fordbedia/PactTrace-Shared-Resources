<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Tests;

use PactTrackSDK\SharedResources\Modules\Signature\Application\UseCases\FindNextPendingSignatureForClientUseCase;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Enums\EnvelopeStatus;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * The client-side chaining query behind Flow B step 4 — see
 * .claude/rules/signature.md.
 */
class FindNextPendingSignatureForClientUseCaseTest extends BaseTest
{
    private FindNextPendingSignatureForClientUseCase $useCase;

    private TestScenarioCollection $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useCase = app(FindNextPendingSignatureForClientUseCase::class);
        $this->tenant = ProviderTenantScenario::make('find-next');
    }

    public function test_it_returns_null_when_nothing_is_pending(): void
    {
        Envelope::query()->delete();

        $this->assertNull($this->useCase->handle($this->tenant['client']));
    }

    public function test_it_finds_the_oldest_sent_pending_envelope_first(): void
    {
        Envelope::query()->delete();

        $newer = $this->envelope(EnvelopeStatus::Sent, now()->subHour());
        $older = $this->envelope(EnvelopeStatus::Sent, now()->subDay());

        $result = $this->useCase->handle($this->tenant['client']);

        $this->assertSame($older->id, $result->id);
    }

    public function test_it_excludes_completed_declined_voided_and_expired_envelopes(): void
    {
        Envelope::query()->delete();

        $this->envelope(EnvelopeStatus::Completed, now());
        $this->envelope(EnvelopeStatus::Declined, now());
        $this->envelope(EnvelopeStatus::Voided, now());
        $this->envelope(EnvelopeStatus::Expired, now());

        $this->assertNull($this->useCase->handle($this->tenant['client']));
    }

    public function test_it_excludes_another_clients_envelopes(): void
    {
        Envelope::query()->delete();

        Envelope::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['otherClient']->id,
            'document_id' => $this->tenant['otherDocument']->id,
            'status' => EnvelopeStatus::Sent,
        ]);

        $this->assertNull($this->useCase->handle($this->tenant['client']));
    }

    public function test_the_exclude_parameter_skips_the_given_envelope(): void
    {
        Envelope::query()->delete();

        $justSigned = $this->envelope(EnvelopeStatus::Sent, now()->subDay());
        $next = $this->envelope(EnvelopeStatus::Sent, now()->subHour());

        $result = $this->useCase->handle($this->tenant['client'], $justSigned->id);

        $this->assertSame($next->id, $result->id);
    }

    private function envelope(EnvelopeStatus $status, $sentAt): Envelope
    {
        return Envelope::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'document_id' => $this->tenant['document']->id,
            'status' => $status,
            'sent_at' => $sentAt,
        ]);
    }
}
