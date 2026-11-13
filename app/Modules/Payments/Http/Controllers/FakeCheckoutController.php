<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Gateways\WebhookEvent;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The "payment page" of the fake gateway: two buttons, Pay and Decline.
 *
 * This is what `paymentUrl` opens in development, so the app's whole payment flow can be
 * tried end to end with no real provider. These routes exist only when the fake gateway is
 * the configured one and the app is not in production (see PaymentsServiceProvider).
 */
final class FakeCheckoutController
{
    /** GET /fake-gateway/checkout/{reference} */
    public function show(string $reference): Response
    {
        $payment = $this->find($reference);
        $amount = number_format($payment->amount_minor / 100, 2).' '.e($payment->currency);
        $action = e(url("/fake-gateway/checkout/$reference"));
        $done = $payment->status === PaymentStatus::Pending ? '' : '<p>This payment is already '.e($payment->status->value).'.</p>';

        return response(<<<HTML
            <!doctype html>
            <html lang="en">
            <head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Fake payment</title></head>
            <body style="font-family: system-ui, sans-serif; max-width: 26rem; margin: 3rem auto; padding: 0 1rem;">
                <h1>Fake payment page</h1>
                <p>Development only. No money moves.</p>
                <p>Amount: <strong>$amount</strong></p>
                $done
                <form method="post" action="$action">
                    <button name="outcome" value="succeeded" style="padding: .7rem 1.2rem;">Pay</button>
                    <button name="outcome" value="failed" style="padding: .7rem 1.2rem;">Decline</button>
                </form>
            </body>
            </html>
            HTML);
    }

    /** POST /fake-gateway/checkout/{reference} */
    public function pay(Request $request, string $reference, PaymentService $payments): Response|RedirectResponse
    {
        $payment = $this->find($reference);
        $succeeded = $request->input('outcome') === 'succeeded';

        // The same code path a real webhook takes.
        $payments->handleWebhook(new WebhookEvent($reference, $succeeded, $payment->amount()));

        $result = $succeeded ? 'success' : 'failed';

        if ($payment->return_url !== null) {
            $separator = str_contains($payment->return_url, '?') ? '&' : '?';

            return redirect()->away($payment->return_url.$separator.'payment='.$result);
        }

        return response("Payment $result. You can close this page.");
    }

    private function find(string $reference): Payment
    {
        return Payment::where('gateway', 'fake')->where('gateway_reference', $reference)->firstOrFail();
    }
}
