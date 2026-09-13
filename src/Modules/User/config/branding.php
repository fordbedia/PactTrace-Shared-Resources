<?php

/**
 * Custom-domain infrastructure config — merged automatically by
 * SharedResourceServiceProvider::loadModules() under `config('branding.*')`,
 * same mechanism as Workspace's `config('workspace.*')` presets.
 *
 * - `custom_domain_target`             the platform hostname every tenant's
 *                                      custom-domain CNAME must point at.
 *                                      Confirm with infra before changing —
 *                                      it's referenced in the DNS
 *                                      instructions shown on
 *                                      /dashboard/branding AND checked
 *                                      literally by DnsCustomDomainVerifier.
 * - `custom_domain_verification_prefix` the TXT-record host prefix
 *                                      (`{prefix}.{domain}`) a tenant
 *                                      publishes their verification token
 *                                      at.
 * - `platform_host_suffixes`          hosts (or suffixes of hosts) that are
 *                                      PactTrack's own — a request on any of
 *                                      these is never treated as a custom
 *                                      domain lookup. See
 *                                      Http\Middleware\ResolveProviderFromCustomHost.
 */
return [
    'custom_domain_target' => env('CUSTOM_DOMAIN_TARGET', 'custom.pacttrack.com'),

    'custom_domain_verification_prefix' => '_pacttrack-verify',

    'platform_host_suffixes' => array_filter(array_map(
        'trim',
        explode(',', env('PLATFORM_HOST_SUFFIXES', 'pacttrack.com'))
    )),
];
