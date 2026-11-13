<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Modules\Payments\Services\PaymentService;
use App\Support\Http\ApiDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CheckoutController
{
    /** POST /sessions/{session}/checkout */
    public function store(Request $request, string $session, PaymentService $payments): JsonResponse
    {
        $data = $request->validate([
            // Any scheme followed by "://", so an app deep link (careeredge://paid) works.
            // The "//" also keeps out "javascript:..." links.
            'returnUrl' => ['bail', 'nullable', 'string', 'max:2048', 'regex:#^[a-z][a-z0-9+.-]*://\S+$#i'],
        ], ['returnUrl.*' => 'returnUrl must be a link such as https://... or an app deep link.']);

        [$payment, $billable] = $payments->checkout($request->user()->id, $session, $data['returnUrl'] ?? null);

        return response()->json([
            'paymentId' => $payment->id,
            'paymentUrl' => $payment->checkout_url,
            // Paying is possible exactly as long as the slot is held.
            'expiresAt' => ApiDate::format($billable->holdExpiresAt),
        ]);
    }
}
