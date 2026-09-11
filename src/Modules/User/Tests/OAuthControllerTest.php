<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Tests;

use Laravel\Sanctum\SanctumServiceProvider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\SocialiteServiceProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Role;
use PactTrackSDK\SharedResources\Modules\User\Models\Provider;
use PactTrackSDK\SharedResources\Modules\User\Models\User;
use PactTrackSDK\SharedResources\TestCase\Extras\LoadsModuleApiRoutes;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;

/**
 * HTTP coverage for `GET /api/auth/{provider}/redirect` and
 * `.../callback` — OAuthController. SanctumServiceProvider is registered
 * (not exercised by these specific routes, but routes/api.php also declares
 * `auth:sanctum` groups the router still has to resolve when the file loads
 * — see TeamInvitationControllerTest for the same requirement on another
 * public route in this file).
 */
class OAuthControllerTest extends BaseTest
{
    use LoadsModuleApiRoutes;

    protected function getPackageProviders($app): array
    {
        // SanctumServiceProvider: not exercised by these routes, but the
        // same routes/api.php declares `auth:sanctum` groups the router
        // still resolves when the file loads (see TeamInvitationControllerTest).
        // SocialiteServiceProvider: Testbench doesn't run package
        // auto-discovery, so `Socialite::fake()` needs it listed explicitly
        // — see the top-level CLAUDE.md, "Unit testing".
        return [...parent::getPackageProviders($app), SanctumServiceProvider::class, SocialiteServiceProvider::class];
    }

    protected function moduleApiRoutes(): array
    {
        return [__DIR__.'/../routes/api.php'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.frontend_url' => 'https://portal.example.test']);
    }

    private function fakeGoogleUser(?string $id = 'g-123', ?string $email = 'jane@example.test', ?string $name = 'Jane Doe'): SocialiteUser
    {
        return (new SocialiteUser())->map(['id' => $id, 'email' => $email, 'name' => $name, 'nickname' => null]);
    }

    public function test_redirect_sends_the_browser_to_the_provider(): void
    {
        Socialite::fake('google');

        $response = $this->get('/api/auth/google/redirect');

        $response->assertRedirect();
        $this->assertStringContainsString('google', (string) $response->headers->get('Location'));
    }

    public function test_an_unsupported_provider_404s(): void
    {
        $response = $this->get('/api/auth/facebook/redirect');

        $response->assertNotFound();
    }

    public function test_sign_in_for_an_existing_linked_account_logs_them_in_and_lands_on_dashboard(): void
    {
        $user = User::factory()->create(['email' => 'jane@example.test', 'google_id' => 'g-123']);
        $user->assignRole(Role::Owner->value);

        Socialite::fake('google', $this->fakeGoogleUser());

        $response = $this->get('/api/auth/google/callback');

        $response->assertRedirect('https://portal.example.test/dashboard');
        $this->assertSame($user->id, auth()->id());
    }

    public function test_sign_in_for_a_client_role_account_lands_on_portal(): void
    {
        $user = User::factory()->create(['email' => 'client@example.test', 'google_id' => 'g-client']);
        $user->assignRole(Role::Client->value);

        Socialite::fake('google', $this->fakeGoogleUser('g-client', 'client@example.test', 'A Client'));

        $response = $this->get('/api/auth/google/callback');

        $response->assertRedirect('https://portal.example.test/portal');
    }

    public function test_sign_in_for_an_unregistered_email_does_not_create_an_account(): void
    {
        Socialite::fake('google', $this->fakeGoogleUser('g-new', 'nobody@example.test'));

        $response = $this->get('/api/auth/google/callback');

        $response->assertRedirect('https://portal.example.test/sign-in?oauth_error=no_account');
        $this->assertNull(User::query()->where('email', 'nobody@example.test')->first());
        $this->assertGuest();
    }

    public function test_sign_up_intent_creates_a_new_provider_account_for_an_unregistered_email(): void
    {
        Socialite::fake('google', $this->fakeGoogleUser('g-new', 'new@example.test', 'New Person'));

        $response = $this->get('/api/auth/google/callback?intent=register');

        $response->assertRedirect('https://portal.example.test/dashboard/create-workspace?onboarding=1');

        $user = User::query()->where('email', 'new@example.test')->sole();
        $this->assertSame(Role::Owner, $user->primaryRole());
        $this->assertSame('g-new', $user->google_id);
        $this->assertSame($user->id, auth()->id());
        Provider::query()->where('owner_user_id', $user->id)->sole();
    }

    public function test_sign_up_intent_signs_in_instead_of_duplicating_an_existing_account(): void
    {
        $user = User::factory()->create(['email' => 'jane@example.test']);
        $user->assignRole(Role::Owner->value);
        $usersBefore = User::count();

        Socialite::fake('google', $this->fakeGoogleUser('g-1', 'jane@example.test', 'Jane Doe'));

        $response = $this->get('/api/auth/google/callback?intent=register');

        // An existing account is a sign-in, not a fresh registration — no
        // onboarding redirect, no second user row.
        $response->assertRedirect('https://portal.example.test/dashboard');
        $this->assertSame($usersBefore, User::count());
        $this->assertSame($user->id, auth()->id());
    }

    public function test_a_provider_response_with_no_email_redirects_with_an_inline_error(): void
    {
        Socialite::fake('google', $this->fakeGoogleUser('g-1', null));

        $response = $this->get('/api/auth/google/callback?intent=register');

        $response->assertRedirect('https://portal.example.test/sign-up?oauth_error=no_email');
        $this->assertGuest();
    }

    public function test_a_failed_provider_exchange_redirects_with_an_inline_error_instead_of_500ing(): void
    {
        Socialite::fake('google', function (): never {
            throw new \RuntimeException('provider unreachable');
        });

        $response = $this->get('/api/auth/google/callback');

        $response->assertRedirect('https://portal.example.test/sign-in?oauth_error=oauth_failed');
        $this->assertGuest();
    }
}
