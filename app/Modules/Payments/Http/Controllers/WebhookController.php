<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Modules\Payments\Gateways\InvalidWebhookSignature;
use App\Modules\Payments\Gateways\PaymentGateway;
use App\Modules\Payments\Services\PaymentService;
use App\Support\Http\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WebhookController
{
    /**
     * POST /payments/webhooks/{provider}
     *
     * Called by the payment provider's servers, not by the app. There is no login here:
     * the signature on the request is what proves who is calling.
     */
    public function handle(Request $request, string $provider, PaymentGateway $gateway, PaymentService $payments): JsonResponse
    {
        if ($provider !== $gateway->name()) {
            throw ApiError::notFound();
        }

        try {
            // The signature is checked before anything in the body is trusted or used.
            $event = $gateway->readWebhook($request);
        } catch (InvalidWebhookSignature) {
            throw ApiError::unauthorized('INVALID_SIGNATURE', 'The webhook signature is not valid');
        }

        $payments->handleWebhook($event);

        // Always 200 once the signature is good, even for a payment we do not know. Any other
        // answer makes the provider resend the same webhook for hours.
        return response()->json(['received' => true]);
    }
}
