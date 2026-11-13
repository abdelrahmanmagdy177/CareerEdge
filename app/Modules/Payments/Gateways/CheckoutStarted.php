<?php

namespace App\Modules\Payments\Gateways;

final readonly class CheckoutStarted
{
    /**
     * @param  string  $reference  the provider's id for this payment
     * @param  string  $url  the page where the customer pays
     */
    public function __construct(public string $reference, public string $url) {}
}
