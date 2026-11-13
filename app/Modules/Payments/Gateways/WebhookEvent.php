<?php

namespace App\Modules\Payments\Gateways;

use App\Support\Money;

/** What a provider told us about one payment, in our own terms. */
final readonly class WebhookEvent
{
    public function __construct(
        public string $reference,
        public bool $succeeded,
        public Money $amount,
    ) {}
}
