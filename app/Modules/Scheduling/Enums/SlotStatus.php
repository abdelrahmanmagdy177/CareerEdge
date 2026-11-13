<?php

namespace App\Modules\Scheduling\Enums;

/**
 * open   → free, candidates can see and book it
 * held   → a candidate started booking and has 15 minutes to pay
 * booked → paid
 */
enum SlotStatus: string
{
    case Open = 'open';
    case Held = 'held';
    case Booked = 'booked';
}
