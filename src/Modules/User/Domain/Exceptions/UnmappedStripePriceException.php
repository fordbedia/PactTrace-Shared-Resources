<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown by {@see \PactTrackSDK\SharedResources\Modules\User\Domain\Ports\StripePriceCatalog::priceIdFor()}
 * when a Plan + BillingInterval has no configured Stripe Price id — fail
 * loud rather than sending Stripe an empty/blank price id, which Stripe
 * itself would reject with a much less legible error.
 */
class UnmappedStripePriceException extends RuntimeException
{
}
