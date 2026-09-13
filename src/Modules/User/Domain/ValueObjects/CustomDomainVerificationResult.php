<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects;

/**
 * The outcome of one {@see \PactTrackSDK\SharedResources\Modules\User\Domain\Ports\CustomDomainVerifier}
 * check — both the TXT (ownership) and CNAME (routing) records are checked
 * independently, so the caller can tell the tenant exactly which one is
 * still missing rather than a generic "verification failed".
 *
 * `$error` is set only when the DNS lookup itself could not be completed
 * (resolver failure/timeout) — as opposed to the records simply not being
 * published yet, which is `$txtMatched`/`$cnameMatched` being false with no
 * error. A verifier implementation must never let a lookup failure escape as
 * an exception; it reports it here instead. See
 * `.claude/rules/branding.md`, "Custom Domain".
 */
final class CustomDomainVerificationResult
{
    public function __construct(
        public readonly bool $txtMatched,
        public readonly bool $cnameMatched,
        public readonly ?string $cnameTarget = null,
        public readonly ?string $error = null,
    ) {
    }

    public function isFullyVerified(): bool
    {
        return $this->txtMatched && $this->cnameMatched;
    }

    /**
     * @return list<'txt'|'cname'>
     */
    public function missingRecords(): array
    {
        $missing = [];

        if (! $this->txtMatched) {
            $missing[] = 'txt';
        }

        if (! $this->cnameMatched) {
            $missing[] = 'cname';
        }

        return $missing;
    }

    /**
     * @return array{txt_matched: bool, cname_matched: bool, cname_target: ?string, missing: list<string>, error: ?string}
     */
    public function toArray(): array
    {
        return [
            'txt_matched' => $this->txtMatched,
            'cname_matched' => $this->cnameMatched,
            'cname_target' => $this->cnameTarget,
            'missing' => $this->missingRecords(),
            'error' => $this->error,
        ];
    }
}
