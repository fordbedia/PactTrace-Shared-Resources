<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown by {@see \PactTrackSDK\SharedResources\Modules\User\Domain\Ports\BillingProvider::constructWebhookEvent()}
 * when the inbound request's signature doesn't verify against
 * `STRIPE_WEBHOOK_SECRET` — StripeWebhookController maps this to a 400,
 * mirroring DocusignWebhookController's own signature-failure handling.
 */
class InvalidStripeWebhookSignatureException extends RuntimeException
{
}
