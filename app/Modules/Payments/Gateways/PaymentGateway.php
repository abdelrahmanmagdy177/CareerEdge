<?php

namespace App\Modules\Payments\Gateways;

use App\Support\Money;
use Illuminate\Http\Request;

/**
 * Everything the app needs from a payment provider. The rest of the code only knows this
 * interface, so adding Paymob or Stripe means writing one class and changing one config value.
 */
interface PaymentGateway
{
    /** The name used in the webhook URL and stored on each payment, e.g. "fake" or "paymob". */
    public function name(): string;

    /**
     * Opens a payment at the provider.
     *
     * @param  string  $paymentId  our id, so the provider can show it in its dashboard
     * @param  string|null  $returnUrl  where to send the customer afterwards
     */
    public function startCheckout(string $paymentId, Money $amount, ?string $returnUrl): CheckoutStarted;

    /**
     * Reads a webhook call from the provider.
     *
     * @throws InvalidWebhookSignature when the call does not prove it comes from the provider
     */
    public function readWebhook(Request $request): WebhookEvent;

    /** Gives money back to the customer. */
    public function refund(string $gatewayReference, Money $amount): void;
}
