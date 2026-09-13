<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Tests;

use PactTrackSDK\SharedResources\Modules\User\Infrastructure\Dns\DnsCustomDomainVerifier;
use PactTrackSDK\SharedResources\Modules\User\Infrastructure\Dns\DnsLookupException;
use PactTrackSDK\SharedResources\Modules\User\Infrastructure\Dns\DnsRecordReader;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;

/**
 * DnsCustomDomainVerifier against a fake DnsRecordReader — no real DNS
 * lookups. See .claude/rules/branding.md, "Custom Domain".
 */
class CustomDomainVerifierTest extends BaseTest
{
    private function fakeReader(array $txt, ?string $cname, bool $throwOnTxt = false, bool $throwOnCname = false): DnsRecordReader
    {
        return new class($txt, $cname, $throwOnTxt, $throwOnCname) implements DnsRecordReader {
            public function __construct(
                private array $txt,
                private ?string $cname,
                private bool $throwOnTxt,
                private bool $throwOnCname,
            ) {
            }

            public function txtRecords(string $host): array
            {
                if ($this->throwOnTxt) {
                    throw new DnsLookupException('resolver unreachable');
                }

                return $this->txt;
            }

            public function cnameTarget(string $host): ?string
            {
                if ($this->throwOnCname) {
                    throw new DnsLookupException('resolver unreachable');
                }

                return $this->cname;
            }
        };
    }

    public function test_verified_when_both_txt_and_cname_match(): void
    {
        $verifier = new DnsCustomDomainVerifier(
            $this->fakeReader(['abc123'], 'custom.pacttrack.com'),
            target: 'custom.pacttrack.com',
        );

        $result = $verifier->verify('portal.example.com', 'abc123');

        $this->assertTrue($result->txtMatched);
        $this->assertTrue($result->cnameMatched);
        $this->assertTrue($result->isFullyVerified());
        $this->assertSame([], $result->missingRecords());
        $this->assertNull($result->error);
    }

    public function test_pending_when_txt_matches_but_cname_does_not(): void
    {
        $verifier = new DnsCustomDomainVerifier(
            $this->fakeReader(['abc123'], 'somewhere-else.com'),
            target: 'custom.pacttrack.com',
        );

        $result = $verifier->verify('portal.example.com', 'abc123');

        $this->assertTrue($result->txtMatched);
        $this->assertFalse($result->cnameMatched);
        $this->assertFalse($result->isFullyVerified());
        $this->assertSame(['cname'], $result->missingRecords());
    }

    public function test_pending_when_cname_matches_but_txt_does_not(): void
    {
        $verifier = new DnsCustomDomainVerifier(
            $this->fakeReader(['wrong-token'], 'custom.pacttrack.com'),
            target: 'custom.pacttrack.com',
        );

        $result = $verifier->verify('portal.example.com', 'abc123');

        $this->assertFalse($result->txtMatched);
        $this->assertTrue($result->cnameMatched);
        $this->assertFalse($result->isFullyVerified());
        $this->assertSame(['txt'], $result->missingRecords());
    }

    public function test_no_records_published_yet_is_pending_not_an_error(): void
    {
        $verifier = new DnsCustomDomainVerifier(
            $this->fakeReader([], null),
            target: 'custom.pacttrack.com',
        );

        $result = $verifier->verify('portal.example.com', 'abc123');

        $this->assertFalse($result->isFullyVerified());
        $this->assertNull($result->error);
        $this->assertSame(['txt', 'cname'], $result->missingRecords());
    }

    public function test_failed_lookup_does_not_throw_and_reports_unverified(): void
    {
        $verifier = new DnsCustomDomainVerifier(
            $this->fakeReader([], null, throwOnTxt: true),
            target: 'custom.pacttrack.com',
        );

        $result = $verifier->verify('portal.example.com', 'abc123');

        $this->assertFalse($result->isFullyVerified());
        $this->assertNotNull($result->error);
    }

    public function test_cname_lookup_failure_after_a_matched_txt_still_reports_the_error(): void
    {
        $verifier = new DnsCustomDomainVerifier(
            $this->fakeReader(['abc123'], null, throwOnCname: true),
            target: 'custom.pacttrack.com',
        );

        $result = $verifier->verify('portal.example.com', 'abc123');

        $this->assertTrue($result->txtMatched);
        $this->assertFalse($result->cnameMatched);
        $this->assertNotNull($result->error);
    }

    public function test_cname_target_comparison_is_case_insensitive_and_ignores_trailing_dot(): void
    {
        $verifier = new DnsCustomDomainVerifier(
            $this->fakeReader(['abc123'], 'CUSTOM.PACTTRACK.COM.'),
            target: 'custom.pacttrack.com',
        );

        $result = $verifier->verify('portal.example.com', 'abc123');

        $this->assertTrue($result->cnameMatched);
    }
}
