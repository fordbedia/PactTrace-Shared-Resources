<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Tests;

use PactTrackSDK\SharedResources\Modules\Signature\Application\Services\ResolveEnvelopeBrand;
use PactTrackSDK\SharedResources\Modules\User\Models\Provider;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;

class ResolveEnvelopeBrandTest extends BaseTest
{
    public function test_starter_tenants_get_pacttracks_own_default_brand(): void
    {
        config(['services.docusign.default_brand_id' => 'pacttrack-default-brand']);

        $provider = Provider::factory()->make(['plan' => 'starter', 'docusign_brand_id' => null]);

        $this->assertSame('pacttrack-default-brand', (new ResolveEnvelopeBrand())->handle($provider));
    }

    public function test_starter_tenants_degrade_to_null_when_no_default_is_configured(): void
    {
        config(['services.docusign.default_brand_id' => null]);

        $provider = Provider::factory()->make(['plan' => 'starter']);

        $this->assertNull((new ResolveEnvelopeBrand())->handle($provider));
    }

    public function test_professional_tenants_get_their_own_brand(): void
    {
        $provider = Provider::factory()->make(['plan' => 'professional', 'docusign_brand_id' => 'tenant-brand-1']);

        $this->assertSame('tenant-brand-1', (new ResolveEnvelopeBrand())->handle($provider));
    }

    public function test_firm_tenants_degrade_to_null_when_they_have_not_configured_a_brand_yet(): void
    {
        $provider = Provider::factory()->make(['plan' => 'firm', 'docusign_brand_id' => null]);

        $this->assertNull((new ResolveEnvelopeBrand())->handle($provider));
    }
}
