<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Tests;

use PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Auth\AuthenticateViaOAuth;
use PactTrackSDK\SharedResources\Modules\User\Domain\Exceptions\OAuthAccountNotFoundException;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\OAuthIdentity;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Role;
use PactTrackSDK\SharedResources\Modules\User\Models\Provider;
use PactTrackSDK\SharedResources\Modules\User\Models\User;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;

/**
 * The matching/linking/creation rule behind "Continue with Google/Microsoft"
 * — see AuthenticateViaOAuth's own docblock for the full decision table.
 * OAuthControllerTest covers the HTTP layer on top of this.
 */
class AuthenticateViaOAuthTest extends BaseTest
{
    private AuthenticateViaOAuth $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useCase = $this->app->make(AuthenticateViaOAuth::class);
    }

    public function test_it_signs_in_a_user_already_linked_to_this_identity(): void
    {
        $user = User::factory()->create(['email' => 'jane@example.test', 'google_id' => 'g-123']);
        $user->assignRole(Role::Owner->value);

        $identity = new OAuthIdentity(OAuthIdentity::GOOGLE, 'g-123', 'jane@example.test', 'Jane Doe');

        $result = $this->useCase->handle($identity, AuthenticateViaOAuth::INTENT_LOGIN);

        $this->assertFalse($result->created);
        $this->assertSame($user->id, $result->user->id);
        $this->assertSame($user->id, auth()->id());
    }

    public function test_it_links_an_existing_account_found_by_email_with_no_prior_link(): void
    {
        $user = User::factory()->create(['email' => 'jane@example.test']);
        $user->assignRole(Role::Owner->value);
        $this->assertNull($user->google_id);

        // Provider-verified email, different casing — normalised the same
        // way password sign-in normalises it.
        $identity = new OAuthIdentity(OAuthIdentity::GOOGLE, 'g-999', 'JANE@Example.Test', 'Jane Doe');

        $result = $this->useCase->handle($identity, AuthenticateViaOAuth::INTENT_LOGIN);

        $this->assertFalse($result->created);
        $this->assertSame('g-999', $user->fresh()->google_id);
    }

    public function test_a_user_may_hold_both_a_google_and_a_microsoft_link_at_once(): void
    {
        $user = User::factory()->create(['email' => 'jane@example.test', 'google_id' => 'g-123']);
        $user->assignRole(Role::Owner->value);

        $identity = new OAuthIdentity(OAuthIdentity::MICROSOFT, 'm-456', 'jane@example.test', 'Jane Doe');
        $this->useCase->handle($identity, AuthenticateViaOAuth::INTENT_LOGIN);

        $fresh = $user->fresh();
        $this->assertSame('g-123', $fresh->google_id);
        $this->assertSame('m-456', $fresh->microsoft_id);
    }

    /**
     * Should never happen with a provider-verified email, but the class must
     * not silently overwrite an existing, disagreeing link rather than
     * report it — see AuthenticateViaOAuth::linkIdentityIfNeeded().
     */
    public function test_it_does_not_overwrite_a_conflicting_existing_link(): void
    {
        $user = User::factory()->create(['email' => 'jane@example.test', 'google_id' => 'g-original']);
        $user->assignRole(Role::Owner->value);

        $identity = new OAuthIdentity(OAuthIdentity::GOOGLE, 'g-different', 'jane@example.test', 'Jane Doe');

        $result = $this->useCase->handle($identity, AuthenticateViaOAuth::INTENT_LOGIN);

        $this->assertFalse($result->created);
        $this->assertSame($user->id, $result->user->id);
        $this->assertSame('g-original', $user->fresh()->google_id);
    }

    public function test_sign_in_never_creates_an_account_for_an_unregistered_email(): void
    {
        $identity = new OAuthIdentity(OAuthIdentity::GOOGLE, 'g-1', 'nobody@example.test', 'Nobody');

        $this->expectException(OAuthAccountNotFoundException::class);

        try {
            $this->useCase->handle($identity, AuthenticateViaOAuth::INTENT_LOGIN);
        } finally {
            $this->assertNull(User::query()->where('email', 'nobody@example.test')->first());
        }
    }

    public function test_sign_up_creates_a_full_provider_account_for_an_unregistered_email(): void
    {
        $identity = new OAuthIdentity(OAuthIdentity::GOOGLE, 'g-1', 'new@example.test', 'New Person');

        $result = $this->useCase->handle($identity, AuthenticateViaOAuth::INTENT_REGISTER);

        $this->assertTrue($result->created);
        $this->assertSame(Role::Owner, $result->user->primaryRole());
        $this->assertSame('g-1', $result->user->google_id);
        $this->assertNotNull($result->user->email_verified_at);
        $this->assertSame($result->user->id, auth()->id());

        // Same side effects as the password sign-up path — one Provider,
        // one owner, wired both ways (see RegisterProvider / .claude/rules/user.md).
        $provider = Provider::query()->where('owner_user_id', $result->user->id)->sole();
        $this->assertSame((int) $provider->getKey(), (int) $result->user->fresh()->provider_id);
    }

    public function test_sign_up_reuses_an_existing_account_instead_of_creating_a_duplicate(): void
    {
        $user = User::factory()->create(['email' => 'jane@example.test']);
        $user->assignRole(Role::Owner->value);
        $usersBefore = User::count();

        $identity = new OAuthIdentity(OAuthIdentity::GOOGLE, 'g-1', 'jane@example.test', 'Jane Doe');
        $result = $this->useCase->handle($identity, AuthenticateViaOAuth::INTENT_REGISTER);

        $this->assertFalse($result->created);
        $this->assertSame($user->id, $result->user->id);
        $this->assertSame($usersBefore, User::count());
    }
}
