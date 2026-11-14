<?php

namespace App\Modules\Scheduling\Console;

use App\Modules\Scheduling\Services\SessionLifecycle;
use Illuminate\Console\Command;

/** Runs every minute from the scheduler (see SchedulingServiceProvider). */
class CloseFinishedSessions extends Command
{
    protected $signature = 'sessions:close-finished';

    protected $description = 'Complete sessions that ran past their end, mark no-shows, and send the "5 minutes left" event';

    public function handle(SessionLifecycle $lifecycle): int
    {
        $soon = $lifecycle->notifyEndingSoon();
        $closed = $lifecycle->closeFinished();

        $this->info("Ending soon: $soon. Completed: {$closed['completed']}. No-show: {$closed['noShow']}.");

        return self::SUCCESS;
    }
}
