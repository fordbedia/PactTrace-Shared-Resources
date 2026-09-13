<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\ProviderRepository;
use Symfony\Component\HttpFoundation\Response;

/**
 * Request-time host resolution for a tenant's custom domain (Part 3).
 *
 * IMPORTANT CONTEXT this class's own behaviour depends on: before this, the
 * app had NO host-based tenant resolution at all — tenancy has always been
 * resolved from the authenticated session's `provider_id`
 * (TenantScopedPolicy, see .claude/rules/user.md), never from the `Host`
 * header, and the SPA is one Next.js build hitting one API origin
 * (`NEXT_PUBLIC_API_URL=/api`), not per-tenant subdomains. So there is no
 * pre-existing "subdomain resolution" for this middleware to extend — it is
 * new infrastructure, and today it has no other consumer: nothing yet reads
 * the `resolved_provider` request attribute this sets. It exists so a
 * request that arrives on a verified custom domain doesn't silently 404 at
 * the web-server layer with no application-level record of why, and so a
 * future feature that needs "which provider does this Host belong to" (e.g.
 * a pre-auth branding lookup for `/portal/login`, see .claude/rules/client.md,
 * "Client portal login") has somewhere to plug in rather than re-deriving
 * this query.
 *
 * Behaviour:
 *  - Host is the app's own primary domain, or a subdomain of it (per
 *    `config('branding.platform_host_suffixes')`) -> pass through
 *    unchanged. This is every request today; nothing about existing
 *    behaviour changes.
 *  - Host matches a `providers.custom_domain` with
 *    `custom_domain_status = 'verified'` -> resolve it, attach it to the
 *    request as `resolved_provider`, pass through.
 *  - Anything else (an unverified custom domain, or a completely unknown
 *    host) -> 404, so a domain someone points at this app's IP without ever
 *    completing verification gets no response, same as the CLAUDE.md-wide
 *    convention of failing closed on an unrecognised tenant reference.
 *
 * Registered in `backend/bootstrap/app.php`'s `api` middleware group.
 */
final class ResolveProviderFromCustomHost
{
    public function __construct(
        private readonly ProviderRepository $providers,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $host = strtolower($request->getHost());

        if ($this->isPlatformHost($host)) {
            return $next($request);
        }

        $provider = $this->providers->findByVerifiedCustomDomain($host);

        abort_if($provider === null, 404);

        $request->attributes->set('resolved_provider', $provider);

        return $next($request);
    }

    private function isPlatformHost(string $host): bool
    {
        // Always trust the app's OWN configured URL, regardless of the
        // suffix list below — this is what actually protects normal SPA
        // traffic from a suffix-list/APP_URL mismatch (e.g. an `.env.*`
        // whose APP_URL host doesn't literally end in a configured
        // suffix) ever 404ing every real request.
        $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        if ($appHost !== '' && $host === $appHost) {
            return true;
        }

        $suffixes = (array) config('branding.platform_host_suffixes', []);

        foreach ($suffixes as $suffix) {
            $suffix = strtolower((string) $suffix);

            if ($suffix !== '' && ($host === $suffix || str_ends_with($host, '.' . $suffix))) {
                return true;
            }
        }

        // Local dev / any host not explicitly configured as a platform
        // suffix (e.g. `localhost`, a bare IP) is treated as a platform
        // host too — only a host that could plausibly BE someone's real
        // custom domain should ever reach the lookup below.
        return $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP) !== false;
    }
}
