<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Tests;

use Illuminate\Http\Request;
use PactTrackSDK\SharedResources\Modules\User\Http\Middleware\ResolveProviderFromCustomHost;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Request-time host resolution (Part 3) — see the middleware's own docblock
 * for why "existing subdomain resolution" (referenced by the original spec
 * for this test class) does not exist in this codebase: there was nothing to
 * extend, so these tests exercise the new behaviour directly rather than a
 * regression against prior behaviour.
 */
class ResolveProviderFromCustomHostTest extends BaseTest
{
    private function middleware(): ResolveProviderFromCustomHost
    {
        return $this->app->make(ResolveProviderFromCustomHost::class);
    }

    private function requestForHost(string $host): Request
    {
        $request = Request::create('https://' . $host . '/api/v1/plans', 'GET');
        $request->headers->set('Host', $host);

        return $request;
    }

    public function test_request_with_verified_custom_domain_host_resolves_to_correct_provider(): void
    {
        $tenant = ProviderTenantScenario::make('resolve-verified');
        $tenant['provider']->forceFill([
            'custom_domain' => 'portal.clientfirm.com',
            'custom_domain_status' => 'verified',
        ])->save();

        $request = $this->requestForHost('portal.clientfirm.com');
        $called = false;

        $response = $this->middleware()->handle($request, function ($req) use (&$called) {
            $called = true;

            return response('ok');
        });

        $this->assertTrue($called);
        $this->assertSame(200, $response->getStatusCode());
        $resolved = $request->attributes->get('resolved_provider');
        $this->assertNotNull($resolved);
        $this->assertSame($tenant['provider']->id, $resolved->id);
    }

    public function test_request_with_unverified_custom_domain_host_404s(): void
    {
        $tenant = ProviderTenantScenario::make('resolve-unverified');
        $tenant['provider']->forceFill([
            'custom_domain' => 'portal.clientfirm.com',
            'custom_domain_status' => 'pending',
        ])->save();

        $this->expectException(NotFoundHttpException::class);

        $this->middleware()->handle($this->requestForHost('portal.clientfirm.com'), fn ($req) => response('ok'));
    }

    public function test_request_with_unknown_host_still_404s(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->middleware()->handle($this->requestForHost('nobody-owns-this.com'), fn ($req) => response('ok'));
    }

    public function test_a_request_on_the_platforms_own_host_is_unaffected(): void
    {
        config(['branding.platform_host_suffixes' => ['pacttrack.com']]);
        $called = false;

        $response = $this->middleware()->handle($this->requestForHost('int.pacttrack.com'), function ($req) use (&$called) {
            $called = true;

            return response('ok');
        });

        $this->assertTrue($called);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull($this->requestForHost('int.pacttrack.com')->attributes->get('resolved_provider'));
    }

    public function test_the_apps_own_app_url_host_is_always_trusted_regardless_of_the_suffix_list(): void
    {
        config(['app.url' => 'https://weird-domain.internal']);
        config(['branding.platform_host_suffixes' => ['pacttrack.com']]);
        $called = false;

        $response = $this->middleware()->handle($this->requestForHost('weird-domain.internal'), function ($req) use (&$called) {
            $called = true;

            return response('ok');
        });

        $this->assertTrue($called);
        $this->assertSame(200, $response->getStatusCode());
    }
}
