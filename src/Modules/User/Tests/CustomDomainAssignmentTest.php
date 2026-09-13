<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Tests;

use PactTrackSDK\SharedResources\Modules\User\Domain\Services\CustomDomainAssignment;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\CustomDomainStatus;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;

/**
 * Pure computation, no I/O — see the class's own docblock for the rules.
 */
class CustomDomainAssignmentTest extends BaseTest
{
    public function test_setting_a_domain_for_the_first_time_starts_pending_with_a_fresh_token(): void
    {
        $attrs = CustomDomainAssignment::apply(null, 'portal.example.com', 'freshtoken');

        $this->assertSame('portal.example.com', $attrs['custom_domain']);
        $this->assertSame(CustomDomainStatus::Pending->value, $attrs['custom_domain_status']);
        $this->assertSame('freshtoken', $attrs['custom_domain_verification_token']);
        $this->assertNull($attrs['custom_domain_verified_at']);
    }

    public function test_changing_domain_resets_to_pending_with_a_new_token(): void
    {
        $attrs = CustomDomainAssignment::apply('old.example.com', 'new.example.com', 'newtoken');

        $this->assertSame('new.example.com', $attrs['custom_domain']);
        $this->assertSame(CustomDomainStatus::Pending->value, $attrs['custom_domain_status']);
        $this->assertSame('newtoken', $attrs['custom_domain_verification_token']);
        $this->assertNull($attrs['custom_domain_verified_at']);
        $this->assertNull($attrs['cloudflare_custom_hostname_id']);
        $this->assertNull($attrs['custom_domain_ssl_status']);
    }

    public function test_clearing_the_domain_resets_to_unverified(): void
    {
        $attrs = CustomDomainAssignment::apply('old.example.com', '', 'unused');

        $this->assertNull($attrs['custom_domain']);
        $this->assertSame(CustomDomainStatus::Unverified->value, $attrs['custom_domain_status']);
        $this->assertNull($attrs['custom_domain_verification_token']);
    }

    public function test_resaving_the_same_domain_is_a_no_op(): void
    {
        $attrs = CustomDomainAssignment::apply('portal.example.com', 'portal.example.com', 'wouldbewasted');

        $this->assertSame([], $attrs);
    }

    public function test_clearing_an_already_empty_domain_is_a_no_op(): void
    {
        $attrs = CustomDomainAssignment::apply(null, '', 'unused');

        $this->assertSame([], $attrs);
    }
}
