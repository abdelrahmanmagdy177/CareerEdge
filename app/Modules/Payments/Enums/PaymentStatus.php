<?php

namespace App\Modules\Payments\Enums;

/**
 * pending  → checkout started, the gateway has not answered yet
 * held     → the money is with us, waiting for the session to happen (the "escrow" step)
 * released → paid out to the recruiter (milestone M6)
 * refunded / partially_refunded → given back to the candidate
 * failed   → the gateway said no, or the hold ran out before paying
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Held = 'held';
    case Released = 'released';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';
    case Failed = 'failed';
}
