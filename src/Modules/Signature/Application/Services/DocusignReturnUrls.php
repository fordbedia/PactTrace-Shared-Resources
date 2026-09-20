<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Application\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;

/**
 * The single place every URL handed to DocuSign is built — embedded-view
 * returnUrls (Sender, Recipient, guest) and the one-time consent redirect.
 *
 * All of them derive from ONE configured public base URL, never from the
 * incoming request's host: DocuSign's iframe navigates the user's browser to
 * these URLs, so a URL that resolves to a loopback/private address from the
 * user's machine makes Chrome's Local Network Access protection block the
 * redirect ("initiated by a public page to connect to devices or servers on
 * your local network"). See .claude/rules/signature.md, "DocuSign-facing
 * URLs".
 *
 * Base URL: `services.docusign.return_base_url` (DOCUSIGN_RETURN_BASE_URL),
 * falling back to `app.frontend_url` (FRONTEND_URL) when unset.
 */
class DocusignReturnUrls
{
    public function baseUrl(): string
    {
        $base = (string) (config('services.docusign.return_base_url') ?: config('app.frontend_url'));

        return rtrim($base, '/');
    }

    public function sender(Envelope $envelope): string
    {
        return $this->build('/docusign-return?flow=sender&envelope=' . $envelope->public_id);
    }

    public function recipient(Envelope $envelope): string
    {
        return $this->build('/docusign-return?flow=recipient&envelope=' . $envelope->public_id);
    }

    public function consentRedirect(): string
    {
        return $this->build('/docusign-return');
    }

    private function build(string $path): string
    {
        $url = $this->baseUrl() . $path;
        $this->warnIfNotPublic($url);

        return $url;
    }

    /**
     * Logs (never throws) when the host we're about to give DocuSign is
     * loopback/private, a local-only TLD, or doesn't resolve publicly at all.
     * Checked at most once per host per 10 minutes. Note this resolves from
     * the *server's* resolver — a per-machine /etc/hosts override on a
     * developer's Mac is invisible here, which is why an unresolvable host is
     * also flagged: it is the same misconfiguration seen from the outside.
     */
    private function warnIfNotPublic(string $url): void
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '' || ! Cache::add("docusign-return-host-checked:{$host}", 1, 600)) {
            return;
        }

        $reason = $this->nonPublicReason($host);

        if ($reason !== null) {
            Log::warning("DocuSign-facing URL host [{$host}] {$reason}. DocuSign's cloud (and Chrome, after the "
                . 'embedded view redirects) cannot reach it — set DOCUSIGN_RETURN_BASE_URL/FRONTEND_URL to a public host.');
        }
    }

    private function nonPublicReason(string $host): ?string
    {
        if ($host === 'localhost' || preg_match('/\.(test|local|localhost|internal|lan)$/', $host)) {
            return 'is a local-only hostname';
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (@gethostbynamel($host) ?: []);

        if ($ips === []) {
            return 'does not resolve in public DNS';
        }

        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return "resolves to a private/loopback address ({$ip})";
            }
        }

        return null;
    }
}
