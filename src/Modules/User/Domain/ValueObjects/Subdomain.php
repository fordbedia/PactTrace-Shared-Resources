<?php

declare(strict_types=1);

namespace PactTraceSDK\SharedResources\Modules\User\Domain\ValueObjects;

use InvalidArgumentException;

/**
 * A provider's subdomain — the hostname their branded portal answers on.
 *
 * This is a value object rather than a string because "what counts as a valid
 * subdomain" is a real rule with teeth: the value becomes a DNS label, so it is
 * bound by DNS's grammar (a-z, 0-9, hyphen; must not start or end with a
 * hyphen; 63 characters max), and it shares a namespace with the hostnames the
 * product itself needs. A tenant who registers "Api Consulting" must not end up
 * owning `api.pacttrace.com`.
 *
 * Framework-free per the hexagonal rule in CLAUDE.md — hence hand-rolled
 * normalisation instead of Str::slug().
 */
final class Subdomain
{
    /** Maximum length of a single DNS label (RFC 1035). */
    public const MAX_LENGTH = 63;

    /**
     * Hostnames the platform reserves for itself. Handing any of these to a
     * tenant would shadow a real route — `www` and `app` are the marketing
     * site and dashboard, `api` is the backend, and the mail names affect
     * deliverability records.
     *
     * @var list<string>
     */
    private const RESERVED = [
        'www', 'api', 'app', 'admin', 'dashboard', 'portal', 'auth', 'login',
        'signup', 'register', 'billing', 'support', 'help', 'status', 'docs',
        'mail', 'smtp', 'imap', 'ftp', 'cdn', 'static', 'assets', 'blog',
        'test', 'staging', 'dev', 'demo', 'pacttrace',
    ];

    private function __construct(
        public readonly string $value,
    ) {
    }

    /**
     * Build from a value that is already meant to be a subdomain — i.e. one the
     * user typed into a "choose your portal address" field. Strict: an invalid
     * value is the user's mistake and they should be told, not silently given
     * something else.
     */
    public static function fromString(string $value): self
    {
        $value = strtolower(trim($value));

        if ($value === '') {
            throw new InvalidArgumentException('Subdomain cannot be empty.');
        }

        if (strlen($value) > self::MAX_LENGTH) {
            throw new InvalidArgumentException(
                sprintf('Subdomain cannot exceed %d characters.', self::MAX_LENGTH)
            );
        }

        if (preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $value) !== 1) {
            throw new InvalidArgumentException(
                "[{$value}] is not a valid subdomain: use letters, numbers and hyphens, "
                . 'starting and ending with a letter or number.'
            );
        }

        if (self::isReserved($value)) {
            throw new InvalidArgumentException("[{$value}] is reserved and cannot be used.");
        }

        return new self($value);
    }

    /**
     * Derive one from arbitrary free text — a business name off the signup
     * form. Lenient by design: the user never typed a subdomain here, so there
     * is no mistake to report, only a best-effort normalisation.
     *
     * Reserved words are deliberately NOT rejected here; they are suffixed
     * instead (see below), because "Demo Consulting LLC" is a legitimate
     * business name and refusing to register it would be absurd.
     */
    public static function fromLabel(string $label): self
    {
        // Transliterate accented characters to their ASCII neighbours where
        // possible (José -> Jose) before discarding anything left over.
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label);
        $slug = strtolower($ascii !== false ? $ascii : $label);

        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        // Empty when the input maps to nothing at all — a business name
        // written entirely in a non-Latin script, say.
        if ($slug === '') {
            $slug = 'provider';
        }

        $slug = self::truncate($slug, self::MAX_LENGTH);

        // A derived slug can still collide with a reserved name; nudge it
        // aside rather than rejecting the registration.
        if (self::isReserved($slug)) {
            $slug = self::truncate($slug, self::MAX_LENGTH - 2) . '-1';
        }

        return new self($slug);
    }

    /**
     * A numbered variant, for when this one is already taken.
     *
     * Truncates the stem so the result still fits in a DNS label — appending
     * blindly would silently produce an over-long hostname for a provider with
     * a very long business name.
     */
    public function withSuffix(string $suffix): self
    {
        $stem = self::truncate($this->value, self::MAX_LENGTH - strlen($suffix) - 1);

        return new self($stem . '-' . $suffix);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    private static function isReserved(string $value): bool
    {
        return in_array($value, self::RESERVED, true);
    }

    /** Trim to length without leaving a trailing hyphen, which DNS forbids. */
    private static function truncate(string $value, int $length): string
    {
        return rtrim(substr($value, 0, max($length, 1)), '-');
    }
}
