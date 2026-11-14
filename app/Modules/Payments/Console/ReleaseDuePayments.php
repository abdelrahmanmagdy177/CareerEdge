<?php

namespace App\Modules\Payments\Console;

use App\Modules\Payments\Services\PaymentSettlement;
use Illuminate\Console\Command;

/** Runs every minute from the scheduler (see PaymentsServiceProvider). */
class ReleaseDuePayments extends Command
{
    protected $signature = 'payments:release-due';

    protected $description = 'Release held payments to recruiters 24 hours after their session completed';

    public function handle(PaymentSettlement $settlement): int
    {
        $this->info("Released {$settlement->releaseDue()} payment(s).");

        return self::SUCCESS;
    }
}
