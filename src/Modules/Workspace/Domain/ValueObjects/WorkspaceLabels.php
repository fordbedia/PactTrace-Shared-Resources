<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Workspace\Domain\ValueObjects;

use InvalidArgumentException;

/**
 * The pair of nouns a workspace uses to describe itself to a client.
 *
 * `client` is what the counterparty calls the provider ("Your Attorney"),
 * `engagement` is what the provider calls a unit of work ("Case", "Matter").
 * They travel together because a screen almost never needs one without the
 * other, and keeping them in one object stops half-applied overrides — a
 * workspace showing "Your Accountant" above a section headed "Case".
 *
 * Immutable and framework-free: a preset produces one of these, and a
 * Workspace's stored columns produce one too, so callers never care which of
 * the two they are reading.
 */
final class WorkspaceLabels
{
    public function __construct(
        public readonly string $client,
        public readonly string $engagement,
    ) {
        if (trim($this->client) === '' || trim($this->engagement) === '') {
            throw new InvalidArgumentException(
                'Workspace labels cannot be blank; omit an override to fall back to the preset instead.'
            );
        }
    }

    /**
     * Build from a loosely-shaped array (a config entry, a form payload).
     *
     * @param  array{client?: string|null, engagement?: string|null}  $labels
     */
    public static function fromArray(array $labels, self $fallback): self
    {
        $client = isset($labels['client']) ? trim((string) $labels['client']) : '';
        $engagement = isset($labels['engagement']) ? trim((string) $labels['engagement']) : '';

        return new self(
            $client === '' ? $fallback->client : $client,
            $engagement === '' ? $fallback->engagement : $engagement,
        );
    }

    /**
     * A copy with either label replaced. A null or blank override keeps the
     * current value, which is what makes "customise just one of them" work.
     */
    public function override(?string $client = null, ?string $engagement = null): self
    {
        $client = $client === null ? '' : trim($client);
        $engagement = $engagement === null ? '' : trim($engagement);

        return new self(
            $client === '' ? $this->client : $client,
            $engagement === '' ? $this->engagement : $engagement,
        );
    }

    /**
     * @return array{client: string, engagement: string}
     */
    public function toArray(): array
    {
        return [
            'client' => $this->client,
            'engagement' => $this->engagement,
        ];
    }

    public function equals(self $other): bool
    {
        return $this->client === $other->client
            && $this->engagement === $other->engagement;
    }
}
