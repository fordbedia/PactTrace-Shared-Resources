<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Dashboard\Tests;

use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\SanctumServiceProvider;
use PactTrackSDK\SharedResources\Modules\Client\Models\Client;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\Matter\Models\Matter;
use PactTrackSDK\SharedResources\Modules\Matter\Models\Milestone;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\Modules\User\Models\Provider;
use PactTrackSDK\SharedResources\Modules\User\Models\User;
use PactTrackSDK\SharedResources\TestCase\Extras\LoadsModuleApiRoutes;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;

/**
 * HTTP coverage for `GET /api/v1/dashboard/summary` — the four stat cards
 * (+ real trend deltas), the storage figure, the signatures-last-7-days
 * series and the matters-in-progress list, all provider-scoped.
 *
 * Time is frozen so the "this week" / "this month" / trailing-7-days
 * boundaries are deterministic.
 */
class DashboardSummaryEndpointTest extends BaseTest
{
    use BuildsDashboardTenant;
    use LoadsModuleApiRoutes;

    private Provider $provider;
    private User $owner;

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), SanctumServiceProvider::class];
    }

    protected function moduleApiRoutes(): array
    {
        return [__DIR__ . '/../routes/api.php'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-15 12:00:00'));

        [$this->provider, $this->owner] = $this->makeProviderTenant('summary');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/dashboard/summary')->assertStatus(401);
    }

    public function test_forbids_client_role_users(): void
    {
        // Role::Client holds `matter.view`, so the permission gate alone
        // would let them through — isProviderSide() is the real barrier.
        $clientUser = $this->makeClientUser($this->provider, 'summary');
        Sanctum::actingAs($clientUser);

        $this->getJson('/api/v1/dashboard/summary')->assertStatus(403);
    }

    public function test_returns_real_provider_scoped_counts_and_excludes_other_providers(): void
    {
        $this->seedProviderFixtures($this->provider);
        $this->seedNoiseForOtherProvider();

        Sanctum::actingAs($this->owner);

        $response = $this->getJson('/api/v1/dashboard/summary')->assertOk();

        // Stat cards.
        $response->assertJsonPath('data.stats.active_matters.value', 3)      // 3 active (on_hold/completed/cancelled excluded)
            ->assertJsonPath('data.stats.active_matters.new_this_week', 6)   // all 6 created at the frozen "now"
            ->assertJsonPath('data.stats.docs_awaiting.value', 3)           // 2 sent + 1 partially_signed
            ->assertJsonPath('data.stats.clients.value', 3)
            ->assertJsonPath('data.stats.clients.new_this_month', 2)        // one client back-dated to July
            ->assertJsonPath('data.stats.signed_this_month.value', 3)
            ->assertJsonPath('data.stats.signed_this_month.previous_month', 2)
            ->assertJsonPath('data.stats.signed_this_month.change_pct', 50); // (3-2)/2*100, JSON-encoded

        // Storage — used bytes is the exact SUM of this provider's document sizes.
        $response->assertJsonPath('data.storage.used_bytes', 1500);
        $this->assertGreaterThan(0, $response->json('data.storage.limit_bytes'));
        $this->assertNotEmpty($response->json('data.storage.used_label'));

        // Signatures — Last 7 Days: exactly 7 day-buckets, zero days included,
        // window 2026-08-09..2026-08-15.
        $series = $response->json('data.signatures_last_7_days');
        $this->assertCount(7, $series);
        $this->assertSame('2026-08-09', $series[0]['date']);
        $this->assertSame('2026-08-15', $series[6]['date']);
        $this->assertSame(1, $series[6]['count']);           // one completed on the 15th
        $this->assertSame(1, $series[1]['count']);           // one completed on the 10th
        $this->assertSame(2, array_sum(array_column($series, 'count'))); // the 5th is out of window

        // Matters in Progress — only active/on_hold, soonest due_date first
        // (nulls last), reusing MatterProgressCalculator for `progress`.
        $inProgress = $response->json('data.matters_in_progress');
        $this->assertCount(4, $inProgress);
        $this->assertSame(['m4', 'm1', 'm2', 'm3'], array_column($inProgress, 'name'));
        $this->assertSame(25, $inProgress[1]['progress']); // m1: 1 of 4 milestones completed
    }

    private function seedProviderFixtures(Provider $provider): void
    {
        $client = Client::factory()->create(['provider_id' => $provider->id, 'status' => 'active']);
        Client::factory()->create(['provider_id' => $provider->id, 'status' => 'invited']);
        Client::factory()->create([
            'provider_id' => $provider->id,
            'status' => 'archived',
            'created_at' => Carbon::parse('2026-07-15 09:00:00'),
        ]);

        $matters = collect([
            ['name' => 'm1', 'status' => 'active', 'due_date' => '2026-08-20'],
            ['name' => 'm2', 'status' => 'active', 'due_date' => '2026-08-25'],
            ['name' => 'm3', 'status' => 'active', 'due_date' => null],
            ['name' => 'm4', 'status' => 'on_hold', 'due_date' => '2026-08-18'],
            ['name' => 'm5', 'status' => 'completed', 'due_date' => null],
            ['name' => 'm6', 'status' => 'cancelled', 'due_date' => null],
        ])->mapWithKeys(fn (array $attrs) => [
            $attrs['name'] => Matter::factory()->create([
                'provider_id' => $provider->id,
                'client_id' => $client->id,
                'name' => $attrs['name'],
                'status' => $attrs['status'],
                'due_date' => $attrs['due_date'],
            ]),
        ]);

        // m1: 4 milestones, 1 completed -> progress 25.
        Milestone::factory()->create(['matter_id' => $matters['m1']->id, 'status' => 'completed', 'completed_at' => Carbon::now()]);
        Milestone::factory()->count(3)->create(['matter_id' => $matters['m1']->id, 'status' => 'pending', 'completed_at' => null]);

        $firstDocument = null;
        $sizes = ['sent' => [100, 200], 'partially_signed' => [300], 'completed' => [400], 'draft' => [500]];
        foreach ($sizes as $status => $bytes) {
            foreach ($bytes as $size) {
                $document = Document::factory()->create([
                    'provider_id' => $provider->id,
                    'client_id' => $client->id,
                    'matter_id' => $matters['m1']->id,
                    'uploaded_by' => $this->owner->id,
                    'status' => $status,
                    'size' => $size,
                    'archived_at' => null,
                ]);
                $firstDocument ??= $document;
            }
        }

        $completedAt = ['2026-08-15', '2026-08-10', '2026-08-05', '2026-07-20', '2026-07-10'];
        foreach ($completedAt as $date) {
            Envelope::factory()->create([
                'provider_id' => $provider->id,
                'client_id' => $client->id,
                'document_id' => $firstDocument->id,
                'status' => 'completed',
                'completed_at' => Carbon::parse($date . ' 10:00:00'),
            ]);
        }
        Envelope::factory()->create([
            'provider_id' => $provider->id,
            'client_id' => $client->id,
            'document_id' => $firstDocument->id,
            'status' => 'draft',
            'completed_at' => null,
        ]);
    }

    private function seedNoiseForOtherProvider(): void
    {
        [$other] = $this->makeProviderTenant('summary-other');
        $client = Client::factory()->create(['provider_id' => $other->id]);

        Matter::factory()->count(2)->create(['provider_id' => $other->id, 'client_id' => $client->id, 'status' => 'active']);
        Document::factory()->count(2)->create([
            'provider_id' => $other->id,
            'client_id' => $client->id,
            'uploaded_by' => $other->owner_user_id,
            'status' => 'sent',
            'size' => 9999,
            'archived_at' => null,
        ]);
        Envelope::factory()->count(2)->create([
            'provider_id' => $other->id,
            'client_id' => $client->id,
            'status' => 'completed',
            'completed_at' => Carbon::now(),
        ]);
    }
}
