<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown when the Billing Portal or a plan change is requested for a
 * provider with no `stripe_customer_id`/`stripe_subscription_id` yet — i.e.
 * one still on the card-less trial from `RegisterProvider` who has never
 * started a Checkout session. The frontend routes such a provider to
 * `POST /billing/checkout` instead; this exception is the backend's own
 * guard against the same gap, since both endpoints assume a Stripe
 * subscription already exists.
 */
class NoStripeCustomerException extends RuntimeException
{
}
