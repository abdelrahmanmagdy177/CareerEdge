<?php

namespace App\Modules\Scheduling\Console;

use App\Modules\Scheduling\Services\HoldExpirer;
use Illuminate\Console\Command;

/** Runs every minute from the scheduler (see SchedulingServiceProvider). */
class ExpireHolds extends Command
{
    protected $signature = 'sessions:expire-holds';

    protected $description = 'Cancel sessions whose 15-minute payment hold has run out and reopen their slots';

    public function handle(HoldExpirer $expirer): int
    {
        $count = $expirer->expireDueHolds();

        $this->info("Cancelled $count unpaid session(s).");

        return self::SUCCESS;
    }
}
