<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Tests;

use PactTrackSDK\SharedResources\Modules\Signature\Application\UseCases\VoidEnvelopeHandler;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Enums\EnvelopeStatus;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Exceptions\EnvelopeCannotTransitionException;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * See .claude/rules/signature.md — the "Void This Envelope" action's actual
 * backend, once wired past the confirm modal's pre-existing open/close-only
 * behavior.
 */
class VoidEnvelopeHandlerTest extends BaseTest
{
    private VoidEnvelopeHandler $handler;

    private TestScenarioCollection $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = app(VoidEnvelopeHandler::class);
        $this->tenant = ProviderTenantScenario::make('void-envelope');
    }

    public function test_voids_a_sent_envelope(): void
    {
        $envelope = Envelope::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'document_id' => $this->tenant['document']->id,
            'status' => EnvelopeStatus::Sent,
        ]);

        $voided = $this->handler->handle($envelope, $this->tenant['owner']);

        $this->assertSame(EnvelopeStatus::Voided, $voided->status);
        $this->assertDatabaseHas('envelopes', [
            'id' => $envelope->id,
            'status' => EnvelopeStatus::Voided->value,
        ]);
    }

    public function test_records_the_optional_reason_in_the_audit_log(): void
    {
        $envelope = Envelope::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'document_id' => $this->tenant['document']->id,
            'status' => EnvelopeStatus::PartiallySigned,
        ]);

        $this->handler->handle($envelope, $this->tenant['owner'], 'Document revision required');

        $this->assertDatabaseHas('audit_logs', [
            'provider_id' => $envelope->provider_id,
            'user_id' => $this->tenant['owner']->id,
            'action' => 'envelope.voided',
            'auditable_type' => Envelope::class,
            'auditable_id' => $envelope->id,
        ]);

        $log = \PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog::query()
            ->where('auditable_id', $envelope->id)
            ->where('action', 'envelope.voided')
            ->firstOrFail();

        $this->assertSame('partially_signed', $log->metadata['previous_status']);
        $this->assertSame('Document revision required', $log->metadata['reason']);
    }

    public function test_refuses_to_void_a_terminal_envelope(): void
    {
        $envelope = Envelope::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'document_id' => $this->tenant['document']->id,
            'status' => EnvelopeStatus::Completed,
        ]);

        try {
            $this->handler->handle($envelope, $this->tenant['owner']);
            $this->fail('Expected EnvelopeCannotTransitionException.');
        } catch (EnvelopeCannotTransitionException) {
            // expected
        }

        $this->assertDatabaseMissing('audit_logs', [
            'auditable_id' => $envelope->id,
            'action' => 'envelope.voided',
        ]);
    }
}
