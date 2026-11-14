<?php

namespace App\Modules\Scheduling\Contracts;

use App\Modules\Scheduling\Enums\SessionStatus;
use Carbon\CarbonInterface;

/** What the Reviews module may know about a session when a candidate reviews or disputes it. */
final readonly class FinishedSession
{
    public function __construct(
        public string $id,
        public string $candidateId,
        public string $recruiterId,
        public SessionStatus $status,
        /** When it really ended, or when it was scheduled to end if nobody pressed "end". */
        public CarbonInterface $endedAt,
    ) {}
}
