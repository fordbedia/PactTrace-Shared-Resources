<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\ProviderRepository;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Subdomain;
use Symfony\Component\HttpFoundation\Response;

/**
 * Request-time host resolution — the single place a `Host` header is turned
 * into "which provider is this request about."
 *
 * Formerly `ResolveProviderFromCustomHost`, custom-domain-only. Renamed and
 * widened to also resolve the platform's own `{subdomain}.pacttrack.com`
 * portal hosts, so there is exactly one strategy-picker instead of two
 * independently-registered middlewares each trying to answer the same
 * question. See .claude/rules/client.md, "Subdomain-based portal host
 * resolution" for the full design.
 *
 * IMPORTANT CONTEXT this class's own behaviour depends on: before the
 * custom-domain work, the app had NO host-based tenant resolution at all —
 * tenancy has always been resolved from the authenticated session's
 * `provider_id` (TenantScopedPolicy, see .claude/rules/user.md), never from
 * the `Host` header. The provider-facing dashboard (and, today, the client
 * portal too — there is only one Next.js build) is served from a single
 * fixed app domain (`APP_URL`/`FRONTEND_URL`, e.g. int.pacttrack.com); this
 * middleware's job is to also recognise a *tenant's own* subdomain or
 * verified custom domain and resolve it to a `Provider`, without disturbing
 * a single byte of behaviour for a request on the platform's own host.
 *
 * Registered PREPENDED to the whole `api` middleware group (not scoped to a
 * `/portal*` route prefix) in `backend/bootstrap/app.php` — deliberately,
 * for two reasons: (1) this codebase has no path-based middleware grouping
 * per module (every module's `routes/api.php` is mounted under the same
 * plain `api` middleware group by SharedResourceServiceProvider::loadModules()),
 * and (2) the one consumer that most needs this to have already run before
 * it executes — the login endpoint's tenancy cross-check, see
 * SessionController::store() — sits at `/api/v1/auth/login`, OUTSIDE any
 * `/portal` prefix, since it is shared with the provider-side `/sign-in`
 * screen. Scoping registration to `/portal*` would silently starve that
 * check. Safety for `/dashboard`-bound requests instead comes from WHERE
 * they run: the dashboard is only ever reached via the platform's own host
 * (never a tenant subdomain or custom domain), which this middleware always
 * passes through unresolved — see `isPlatformHost()`.
 *
 * Host classification, in order:
 *  1. The app's own configured host (`config('app.url')`), a bare/`www.`
 *     platform suffix (`config('branding.platform_host_suffixes')`), a
 *     RESERVED label under that suffix (see Domain\ValueObjects\Subdomain —
 *     `int`, `api`, `admin`, …), `localhost`, or a bare IP -> PLATFORM: pass
 *     through unchanged, `resolved_provider` left unset. This is every
 *     request today; nothing about existing dashboard/marketing behaviour
 *     changes.
 *  2. A syntactically valid, non-reserved single-label subdomain of a
 *     configured platform suffix (e.g. `contislawfirm.pacttrack.com`) ->
 *     SUBDOMAIN: look up `providers.subdomain`. Found -> attach as
 *     `resolved_provider`, pass through. Not found -> 404 (an unregistered
 *     subdomain gets no response, same fail-closed convention as an unknown
 *     custom domain).
 *  3. Anything else -> CUSTOM DOMAIN: look up a verified
 *     `providers.custom_domain`. Found -> attach, pass through. Not found ->
 *     404.
 */
final class ResolveProviderFromHost
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

        $label = $this->subdomainLabel($host);

        if ($label !== null) {
            $provider = $this->providers->findBySubdomain($label);

            abort_if($provider === null, 404);

            $request->attributes->set('resolved_provider', $provider);

            return $next($request);
        }

        $provider = $this->providers->findByVerifiedCustomDomain($host);

        abort_if($provider === null, 404);

        $request->attributes->set('resolved_provider', $provider);

        return $next($request);
    }

    /**
     * True for a host that must never be resolved to a tenant — the app's
     * own domain, the bare/`www` marketing host, a reserved label under the
     * platform suffix, or a local-dev host.
     */
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

        foreach ($this->suffixes() as $suffix) {
            if ($host === $suffix) {
                return true;
            }

            if (! str_ends_with($host, '.' . $suffix)) {
                continue;
            }

            $label = substr($host, 0, -(strlen($suffix) + 1));

            // A multi-level or reserved/invalid label under our own suffix is
            // platform namespace (or simply not a real tenant subdomain),
            // never a lookup candidate — e.g. `www.pacttrack.com`,
            // `int.pacttrack.com`, `api.pacttrack.com`.
            if (str_contains($label, '.') || ! Subdomain::isValidLabel($label)) {
                return true;
            }

            // A syntactically valid, non-reserved label -> handled by
            // subdomainLabel()/handle() as a lookup candidate, not platform.
            return false;
        }

        // Local dev / any host not explicitly configured as a platform
        // suffix (e.g. `localhost`, a bare IP) is treated as a platform
        // host too — only a host that could plausibly BE someone's real
        // subdomain or custom domain should ever reach a lookup below.
        return $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * The bare subdomain label to look up, when `$host` is a single-level,
     * syntactically valid, non-reserved subdomain of a configured platform
     * suffix. Null otherwise (isPlatformHost() already filtered out every
     * case that would make this ambiguous, so this only re-derives the
     * label for the one case that survived).
     */
    private function subdomainLabel(string $host): ?string
    {
        foreach ($this->suffixes() as $suffix) {
            if ($host === $suffix || ! str_ends_with($host, '.' . $suffix)) {
                continue;
            }

            $label = substr($host, 0, -(strlen($suffix) + 1));

            if (! str_contains($label, '.') && Subdomain::isValidLabel($label)) {
                return $label;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function suffixes(): array
    {
        return array_map('strtolower', (array) config('branding.platform_host_suffixes', []));
    }
}
