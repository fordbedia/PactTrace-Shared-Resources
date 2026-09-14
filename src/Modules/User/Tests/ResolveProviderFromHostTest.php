<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Tests;

use Illuminate\Http\Request;
use PactTrackSDK\SharedResources\Modules\User\Http\Middleware\ResolveProviderFromHost;
use PactTrackSDK\SharedResources\Modules\User\Models\Provider;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Request-time host resolution — subdomain AND custom-domain, both handled
 * by the one middleware (see ResolveProviderFromHost's own docblock for why
 * "existing subdomain resolution" never existed to extend before this: there
 * was nothing to extend, so these tests exercise the new behaviour directly
 * rather than a regression against prior behaviour).
 */
class ResolveProviderFromHostTest extends BaseTest
{
    private function middleware(): ResolveProviderFromHost
    {
        return $this->app->make(ResolveProviderFromHost::class);
    }

    private function requestForHost(string $host, string $path = '/api/v1/plans'): Request
    {
        $request = Request::create('https://' . $host . $path, 'GET');
        $request->headers->set('Host', $host);

        return $request;
    }

    private function pass(Request $request): array
    {
        $called = false;

        $response = $this->middleware()->handle($request, function ($req) use (&$called) {
            $called = true;

            return response('ok');
        });

        return [$called, $response];
    }

    // ── subdomain resolution ────────────────────────────────────────────

    public function test_subdomain_host_resolves_to_correct_provider(): void
    {
        $tenant = ProviderTenantScenario::make('subdomain-resolve');
        $tenant['provider']->forceFill(['subdomain' => 'contislawfirm'])->save();

        $request = $this->requestForHost('contislawfirm.pacttrack.com');
        [$called, $response] = $this->pass($request);

        $this->assertTrue($called);
        $this->assertSame(200, $response->getStatusCode());
        $resolved = $request->attributes->get('resolved_provider');
        $this->assertNotNull($resolved);
        $this->assertSame($tenant['provider']->id, $resolved->id);
        $this->assertInstanceOf(Provider::class, $resolved);
    }

    public function test_unknown_subdomain_404s(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->middleware()->handle(
            $this->requestForHost('nobody-registered-this.pacttrack.com'),
            fn ($req) => response('ok'),
        );
    }

    public function test_a_reserved_label_under_the_platform_suffix_is_never_treated_as_a_subdomain_lookup(): void
    {
        // "int" is reserved (see Domain\ValueObjects\Subdomain::RESERVED) and
        // is also the app's own APP_URL host — but even a reserved label that
        // ISN'T the exact APP_URL host (e.g. "docs", "admin") must never
        // reach a database lookup or 404: it's platform namespace.
        [$called, $response] = $this->pass($this->requestForHost('docs.pacttrack.com'));

        $this->assertTrue($called);
        $this->assertSame(200, $response->getStatusCode());
    }

    // ── custom-domain resolution (unchanged behaviour, same middleware) ──

    public function test_verified_custom_domain_still_resolves_via_the_same_middleware(): void
    {
        $tenant = ProviderTenantScenario::make('resolve-verified');
        $tenant['provider']->forceFill([
            'custom_domain' => 'portal.clientfirm.com',
            'custom_domain_status' => 'verified',
        ])->save();

        $request = $this->requestForHost('portal.clientfirm.com');
        [$called, $response] = $this->pass($request);

        $this->assertTrue($called);
        $this->assertSame(200, $response->getStatusCode());
        $resolved = $request->attributes->get('resolved_provider');
        $this->assertNotNull($resolved);
        $this->assertSame($tenant['provider']->id, $resolved->id);
    }

    public function test_unverified_custom_domain_404s(): void
    {
        $tenant = ProviderTenantScenario::make('resolve-unverified');
        $tenant['provider']->forceFill([
            'custom_domain' => 'portal.clientfirm.com',
            'custom_domain_status' => 'pending',
        ])->save();

        $this->expectException(NotFoundHttpException::class);

        $this->middleware()->handle($this->requestForHost('portal.clientfirm.com'), fn ($req) => response('ok'));
    }

    public function test_unknown_host_still_404s(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->middleware()->handle($this->requestForHost('nobody-owns-this.com'), fn ($req) => response('ok'));
    }

    // ── platform host / dashboard safety ─────────────────────────────────

    public function test_a_request_on_the_platforms_own_host_is_unaffected(): void
    {
        config(['branding.platform_host_suffixes' => ['pacttrack.com']]);

        [$called, $response] = $this->pass($this->requestForHost('int.pacttrack.com'));

        $this->assertTrue($called);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull($this->requestForHost('int.pacttrack.com')->attributes->get('resolved_provider'));
    }

    public function test_dashboard_routes_are_unaffected_by_host_even_when_a_tenant_subdomain_exists(): void
    {
        $tenant = ProviderTenantScenario::make('dashboard-unaffected');
        $tenant['provider']->forceFill(['subdomain' => 'someoneelsesfirm'])->save();

        $request = $this->requestForHost('int.pacttrack.com', '/api/v1/user');
        [$called, $response] = $this->pass($request);

        $this->assertTrue($called);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull($request->attributes->get('resolved_provider'));
    }

    public function test_the_apps_own_app_url_host_is_always_trusted_regardless_of_the_suffix_list(): void
    {
        config(['app.url' => 'https://weird-domain.internal']);
        config(['branding.platform_host_suffixes' => ['pacttrack.com']]);

        [$called, $response] = $this->pass($this->requestForHost('weird-domain.internal'));

        $this->assertTrue($called);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_local_dev_host_does_not_break_existing_tests(): void
    {
        [$calledLocalhost, $localhostResponse] = $this->pass($this->requestForHost('localhost'));
        [$calledIp, $ipResponse] = $this->pass($this->requestForHost('127.0.0.1'));

        $this->assertTrue($calledLocalhost);
        $this->assertSame(200, $localhostResponse->getStatusCode());
        $this->assertTrue($calledIp);
        $this->assertSame(200, $ipResponse->getStatusCode());
    }
}
