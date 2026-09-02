<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Notification\Tests;

use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\SanctumServiceProvider;
use PactTrackSDK\SharedResources\Modules\Notification\Application\Preferences\NotificationPreferenceResolver;
use PactTrackSDK\SharedResources\Modules\Notification\Database\Seeders\NotificationTypeSeeder;
use PactTrackSDK\SharedResources\Modules\Notification\Models\NotificationType;
use PactTrackSDK\SharedResources\Modules\Notification\Models\UserNotification;
use PactTrackSDK\SharedResources\Modules\Notification\Support\Notification;
use PactTrackSDK\SharedResources\TestCase\Extras\LoadsModuleApiRoutes;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * The `Notification::isset('key')` helper + the `/notification` screen's
 * backend (NotificationPreferenceController).
 *
 * Seeds the catalogue via NotificationTypeSeeder in setUp so the class is
 * self-contained (the DB snapshot also carries it, but the seeder is the
 * source of truth and re-running it is idempotent).
 */
class NotificationPreferenceTest extends BaseTest
{
    use LoadsModuleApiRoutes;

    private TestScenarioCollection $tenant;

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

        (new NotificationTypeSeeder())->run();
        $this->tenant = ProviderTenantScenario::make('notif-pref');
    }

    /* ── helper ─────────────────────────────────────────────────────── */

    public function test_isset_uses_the_type_default_when_the_user_has_no_override(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        // new_doc_uploaded default_email = true; milestone_updated = false.
        $this->assertTrue(Notification::isset('new_doc_uploaded'));
        $this->assertFalse(Notification::isset('milestone_updated'));
    }

    public function test_isset_reflects_a_user_override(): void
    {
        $user = $this->tenant['owner'];
        Sanctum::actingAs($user);

        $type = NotificationType::where('key', 'new_doc_uploaded')->firstOrFail();
        UserNotification::create([
            'user_id' => $user->getKey(),
            'notification_type_id' => $type->getKey(),
            'email' => false,
            'in_app' => true,
            'sms' => false,
        ]);
        app(NotificationPreferenceResolver::class)->flush();

        $this->assertFalse(Notification::isset('new_doc_uploaded'));
        $this->assertFalse(Notification::isset('new_doc_uploaded', $user, 'email'));
    }

    public function test_locked_channel_is_always_on_regardless_of_override(): void
    {
        $user = $this->tenant['owner'];
        Sanctum::actingAs($user);

        $type = NotificationType::where('key', 'security_alerts')->firstOrFail();
        UserNotification::create([
            'user_id' => $user->getKey(),
            'notification_type_id' => $type->getKey(),
            'email' => false,
            'in_app' => false,
            'sms' => false,
        ]);
        app(NotificationPreferenceResolver::class)->flush();

        $this->assertTrue(Notification::isset('security_alerts'));
    }

    public function test_isset_is_false_for_an_unknown_key_and_with_no_user(): void
    {
        Sanctum::actingAs($this->tenant['owner']);
        $this->assertFalse(Notification::isset('does_not_exist'));

        // No authenticated user, no explicit user -> false.
        auth()->forgetGuards();
        $this->assertFalse(Notification::isset('new_doc_uploaded'));
    }

    public function test_global_helper_function_matches_the_class(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $this->assertSame(
            Notification::isset('signature_completed'),
            notification_enabled('signature_completed'),
        );
    }

    /* ── write helpers ─────────────────────────────────────────────── */

    public function test_disable_then_enable_round_trips_and_never_drops_rows(): void
    {
        $user = $this->tenant['owner'];
        Sanctum::actingAs($user);

        $this->assertTrue(Notification::isset('new_doc_uploaded'));

        $this->assertFalse(Notification::disable('new_doc_uploaded'));
        $this->assertFalse(Notification::isset('new_doc_uploaded'));
        // Writing one key materialises the whole per-user set — it must never
        // leave the user with fewer rows than the catalogue.
        $this->assertSame(
            NotificationType::count(),
            UserNotification::where('user_id', $user->getKey())->count(),
        );

        $this->assertTrue(Notification::enable('new_doc_uploaded'));
        $this->assertTrue(Notification::isset('new_doc_uploaded'));
        $this->assertSame(
            NotificationType::count(),
            UserNotification::where('user_id', $user->getKey())->count(),
        );
    }

    public function test_toggling_one_key_leaves_every_other_row_intact(): void
    {
        $user = $this->tenant['owner'];
        Sanctum::actingAs($user);

        // Prime a couple of explicit overrides.
        Notification::disable('payment_received');
        Notification::set('signature_completed', ['email' => false, 'sms' => false]);
        $before = UserNotification::where('user_id', $user->getKey())
            ->get()
            ->keyBy('notification_type_id');

        // A single unrelated toggle.
        Notification::disable('new_doc_uploaded');

        $after = UserNotification::where('user_id', $user->getKey())
            ->get()
            ->keyBy('notification_type_id');

        $this->assertSame($before->count(), $after->count());
        $this->assertFalse((bool) $after[NotificationType::where('key', 'payment_received')->value('id')]->email);
        $this->assertFalse((bool) $after[NotificationType::where('key', 'signature_completed')->value('id')]->email);
        $this->assertFalse((bool) $after[NotificationType::where('key', 'signature_completed')->value('id')]->sms);
    }

    public function test_disable_targets_an_explicit_user_not_the_actor(): void
    {
        $actor = $this->tenant['owner'];
        $target = $this->tenant['staff'];
        Sanctum::actingAs($actor);

        Notification::disable('new_doc_uploaded', $target);

        $this->assertFalse(Notification::isset('new_doc_uploaded', $target));
        $this->assertTrue(Notification::isset('new_doc_uploaded', $actor));
        $this->assertSame(0, UserNotification::where('user_id', $actor->getKey())->count());
    }

    public function test_disable_is_a_no_op_for_a_locked_channel(): void
    {
        $user = $this->tenant['owner'];
        Sanctum::actingAs($user);

        $this->assertTrue(Notification::disable('security_alerts'));
        $this->assertSame(0, UserNotification::where('user_id', $user->getKey())->count());
    }

    public function test_set_writes_multiple_channels_without_disturbing_others(): void
    {
        $user = $this->tenant['owner'];
        Sanctum::actingAs($user);

        $row = Notification::set('signature_completed', ['email' => false, 'sms' => false]);

        $this->assertFalse($row['email']);
        $this->assertFalse($row['sms']);
        $this->assertTrue($row['in_app']); // untouched default
    }

    public function test_reset_via_helper_restores_defaults_without_deleting_rows(): void
    {
        $user = $this->tenant['owner'];
        Sanctum::actingAs($user);

        Notification::disable('new_doc_uploaded');
        Notification::disable('payment_received');

        Notification::reset();

        // Rows are preserved (never deleted) and every value is back to default.
        $this->assertSame(
            NotificationType::count(),
            UserNotification::where('user_id', $user->getKey())->count(),
        );
        $this->assertTrue(Notification::isset('new_doc_uploaded'));
        $this->assertTrue(Notification::isset('payment_received'));
    }

    public function test_write_helpers_throw_for_unknown_key_and_no_user(): void
    {
        Sanctum::actingAs($this->tenant['owner']);
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Notification::enable('does_not_exist');
    }

    public function test_write_helpers_throw_without_a_resolvable_user(): void
    {
        auth()->forgetGuards();

        $this->expectException(\InvalidArgumentException::class);
        Notification::disable('new_doc_uploaded');
    }

    public function test_global_notification_set_matches_the_class(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        notification_set('new_doc_uploaded', false);
        $this->assertFalse(Notification::isset('new_doc_uploaded'));

        notification_set('new_doc_uploaded', true);
        $this->assertTrue(Notification::isset('new_doc_uploaded'));
    }

    /* ── HTTP ───────────────────────────────────────────────────────── */

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/v1/notification-preferences')->assertStatus(401);
    }

    public function test_index_returns_the_effective_catalogue(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $response = $this->getJson('/api/v1/notification-preferences')->assertOk();

        $response->assertJsonCount(NotificationType::count(), 'data');

        $rows = collect($response->json('data'))->keyBy('key');
        $this->assertTrue($rows['new_doc_uploaded']['email']);
        $this->assertFalse($rows['milestone_updated']['email']);
        $this->assertTrue($rows['security_alerts']['email']);
        $this->assertTrue($rows['security_alerts']['email_locked']);
        // channel columns travel on the wire even though the UI ignores them
        $this->assertArrayHasKey('in_app', $rows['new_doc_uploaded']);
        $this->assertArrayHasKey('sms', $rows['new_doc_uploaded']);
    }

    public function test_patch_toggles_email_and_persists_one_override_row(): void
    {
        $user = $this->tenant['owner'];
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/notification-preferences/new_doc_uploaded', ['email' => false])
            ->assertOk()
            ->assertJsonPath('data.key', 'new_doc_uploaded')
            ->assertJsonPath('data.email', false);

        $typeId = NotificationType::where('key', 'new_doc_uploaded')->value('id');

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $user->getKey(),
            'notification_type_id' => $typeId,
            'email' => false,
        ]);

        // A single-channel PATCH must not disturb the other channels.
        $row = UserNotification::where('user_id', $user->getKey())
            ->where('notification_type_id', $typeId)
            ->firstOrFail();
        $this->assertTrue((bool) $row->in_app); // new_doc_uploaded default_in_app = true
    }

    public function test_patch_ignores_a_locked_channel(): void
    {
        $user = $this->tenant['owner'];
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/notification-preferences/security_alerts', ['email' => false])
            ->assertOk()
            ->assertJsonPath('data.email', true)
            ->assertJsonPath('data.email_locked', true);

        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $user->getKey(),
        ]);
    }

    public function test_patch_unknown_key_is_404(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $this->patchJson('/api/v1/notification-preferences/nope', ['email' => true])
            ->assertStatus(404);
    }

    public function test_patch_empty_body_is_422(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $this->patchJson('/api/v1/notification-preferences/new_doc_uploaded', [])
            ->assertStatus(422);
    }

    public function test_reset_clears_every_override_for_the_user(): void
    {
        $user = $this->tenant['owner'];
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/notification-preferences/new_doc_uploaded', ['email' => false])->assertOk();
        $this->patchJson('/api/v1/notification-preferences/payment_received', ['email' => false])->assertOk();

        $response = $this->postJson('/api/v1/notification-preferences/reset')->assertOk();

        // Reset restores defaults in place — it does not delete the user's rows.
        $this->assertSame(
            NotificationType::count(),
            UserNotification::where('user_id', $user->getKey())->count(),
        );
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $user->getKey(),
            'notification_type_id' => NotificationType::where('key', 'new_doc_uploaded')->value('id'),
            'email' => true,
        ]);
        $rows = collect($response->json('data'))->keyBy('key');
        $this->assertTrue($rows['new_doc_uploaded']['email']); // back to default
    }

    public function test_reset_does_not_touch_another_users_overrides(): void
    {
        $mine = $this->tenant['owner'];
        $other = $this->tenant['staff'];

        $type = NotificationType::where('key', 'new_doc_uploaded')->firstOrFail();
        UserNotification::create([
            'user_id' => $other->getKey(),
            'notification_type_id' => $type->getKey(),
            'email' => false,
            'in_app' => false,
            'sms' => false,
        ]);

        Sanctum::actingAs($mine);
        $this->postJson('/api/v1/notification-preferences/reset')->assertOk();

        $this->assertSame(1, UserNotification::where('user_id', $other->getKey())->count());
    }
}
