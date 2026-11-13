<?php

namespace App\Modules\Scheduling\Enums;

/**
 * The life of a session (docs/PLAN.md §4):
 *
 *   pending_payment → confirmed → in_progress → completed → (disputed → completed | refunded)
 *        ↓                ↓
 *    cancelled        cancelled / no_show
 */
enum SessionStatus: string
{
    case PendingPayment = 'pending_payment';
    case Confirmed = 'confirmed';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Disputed = 'disputed';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';
    case Refunded = 'refunded';

    /**
     * Statuses in which the session no longer takes up the candidate's or the recruiter's time.
     * The same list is written into a database constraint in the sessions migration.
     *
     * @return list<string>
     */
    public static function releasedValues(): array
    {
        return [self::Cancelled->value, self::NoShow->value, self::Refunded->value];
    }

    /** Still ahead of us or happening now. */
    public function isUpcoming(): bool
    {
        return in_array($this, [self::PendingPayment, self::Confirmed, self::InProgress], true);
    }
}
