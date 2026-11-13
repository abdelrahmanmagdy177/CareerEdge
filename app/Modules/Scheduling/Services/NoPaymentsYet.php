<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Scheduling\Contracts\PaymentStatusLookup;

/** Used only if the Payments module is not installed. */
final class NoPaymentsYet implements PaymentStatusLookup
{
    public function statusesFor(array $sessionIds): array
    {
        return [];
    }
}
