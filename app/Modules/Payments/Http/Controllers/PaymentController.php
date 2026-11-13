<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Modules\Payments\Models\Payment;
use App\Modules\Scheduling\Contracts\SessionBilling;
use App\Support\Http\ApiDate;
use App\Support\Http\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PaymentController
{
    /** GET /sessions/{session}/payment: the newest payment of a session, for its participants and admins. */
    public function show(Request $request, string $session, SessionBilling $sessions): JsonResponse
    {
        $user = $request->user();
        $billable = $sessions->find($session);

        $mayRead = $billable !== null && match ($user->role->value) {
            'admin' => true,
            'recruiter' => $billable->recruiterId === $user->id && $billable->visibleToRecruiter,
            default => $billable->candidateId === $user->id,
        };

        $payment = $mayRead ? Payment::where('session_id', $session)->orderByDesc('id')->first() : null;
        if ($payment === null) {
            throw ApiError::notFound();
        }

        $date = fn ($value) => $value === null ? null : ApiDate::format($value);

        return response()->json([
            'id' => $payment->id,
            'sessionId' => $payment->session_id,
            'amount' => $payment->amount()->toArray(),
            'refunded' => $payment->refunded()->toArray(),
            'status' => $payment->status->value,
            'heldAt' => $date($payment->held_at),
            'releaseScheduledAt' => $date($payment->release_scheduled_at),
            'releasedAt' => $date($payment->released_at),
            'refundedAt' => $date($payment->refunded_at),
        ]);
    }
}
