<?php

declare(strict_types=1);

namespace PactTraceSDK\SharedResources\Modules\User\Tests;

use InvalidArgumentException;
use PactTraceSDK\SharedResources\Modules\User\Domain\Ports\SubdomainAvailability;
use PactTraceSDK\SharedResources\Modules\User\Domain\Services\SubdomainAllocator;
use PactTraceSDK\SharedResources\Modules\User\Domain\ValueObjects\Subdomain;
use PactTraceSDK\SharedResources\TestCase\Migrations\BaseTest;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Covers the domain rules that used to live inside RegisterProvider.
 *
 * Note what is absent: no factories, no seeding, no provider records. Pulling
 * subdomain derivation out of the use case and behind the SubdomainAvailability
 * port is what makes that possible — the allocator is exercised against an
 * in-memory fake, so these cases run without touching MySQL at all.
 *
 * (Still extends the module BaseTest per the harness rule in CLAUDE.md, even
 * though nothing here needs the database.)
 */
class SubdomainTest extends BaseTest
{
    public static function derivations(): array
    {
        return [
            'plain business name' => ['Smith Law', 'smith-law'],
            'punctuation is dropped' => ['José Álvarez & Co.', 'jose-alvarez-co'],
            'runs of separators collapse' => ['Doe   ---  Law', 'doe-law'],
            'leading/trailing junk trimmed' => ['  !!Doe Law!!  ', 'doe-law'],
            'case folded' => ['DOE LAW', 'doe-law'],
            'digits survive' => ['Studio 54 Consulting', 'studio-54-consulting'],
            'unmappable script falls back' => ['弁護士事務所', 'provider'],
            'reserved word is nudged aside' => ['Support', 'support-1'],
            'reserved only on exact match' => ['API Consulting', 'api-consulting'],
        ];
    }

    #[DataProvider('derivations')]
    public function test_it_derives_a_subdomain_from_a_business_name(string $input, string $expected): void
    {
        $this->assertSame($expected, Subdomain::fromLabel($input)->value);
    }

    public function test_a_derived_subdomain_never_exceeds_a_dns_label(): void
    {
        $subdomain = Subdomain::fromLabel(str_repeat('verylongfirmname ', 12));

        $this->assertLessThanOrEqual(Subdomain::MAX_LENGTH, strlen($subdomain->value));
        $this->assertStringEndsNotWith('-', $subdomain->value);
    }

    public function test_suffixing_a_long_subdomain_stays_within_the_limit(): void
    {
        $subdomain = Subdomain::fromLabel(str_repeat('verylongfirmname ', 12))->withSuffix('12');

        $this->assertLessThanOrEqual(Subdomain::MAX_LENGTH, strlen($subdomain->value));
        $this->assertStringEndsWith('-12', $subdomain->value);
    }

    public static function invalidExplicitValues(): array
    {
        return [
            'empty' => [''],
            'whitespace only' => ['   '],
            'leading hyphen' => ['-doe-law'],
            'trailing hyphen' => ['doe-law-'],
            'illegal characters' => ['doe law!'],
            'underscore' => ['doe_law'],
            'reserved' => ['www'],
            'reserved: api' => ['api'],
            'too long' => [str_repeat('a', Subdomain::MAX_LENGTH + 1)],
        ];
    }

    /**
     * A value the user typed is held to a stricter standard than one derived
     * from their business name: it is a mistake worth reporting, not something
     * to silently rewrite.
     */
    #[DataProvider('invalidExplicitValues')]
    public function test_it_rejects_an_invalid_explicit_subdomain(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        Subdomain::fromString($value);
    }

    public function test_it_accepts_a_valid_explicit_subdomain(): void
    {
        $this->assertSame('doe-law', Subdomain::fromString('  Doe-Law  ')->value);
    }

    public function test_it_allocates_the_desired_subdomain_when_free(): void
    {
        $allocator = new SubdomainAllocator($this->availability());

        $this->assertSame(
            'smith-law',
            $allocator->allocate(Subdomain::fromLabel('Smith Law'))->value
        );
    }

    public function test_it_walks_past_taken_subdomains(): void
    {
        $allocator = new SubdomainAllocator($this->availability('smith-law', 'smith-law-2'));

        $this->assertSame(
            'smith-law-3',
            $allocator->allocate(Subdomain::fromLabel('Smith Law'))->value
        );
    }

    /**
     * Past the sequential ceiling it falls back to a random suffix rather than
     * failing — a provider must always be able to register, however unlucky
     * their business name.
     */
    public function test_it_falls_back_to_a_random_suffix_when_every_variant_is_taken(): void
    {
        $taken = ['smith-law'];
        for ($n = 2; $n <= 50; $n++) {
            $taken[] = 'smith-law-' . $n;
        }

        $allocated = (new SubdomainAllocator($this->availability(...$taken)))
            ->allocate(Subdomain::fromLabel('Smith Law'));

        $this->assertNotContains($allocated->value, $taken);
        $this->assertStringStartsWith('smith-law-', $allocated->value);
    }

    /** An in-memory stand-in for the Eloquent repository. */
    private function availability(string ...$taken): SubdomainAvailability
    {
        return new class ($taken) implements SubdomainAvailability {
            /** @param list<string> $taken */
            public function __construct(private readonly array $taken)
            {
            }

            public function isTaken(Subdomain $subdomain): bool
            {
                return in_array($subdomain->value, $this->taken, true);
            }
        };
    }
}
