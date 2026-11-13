<?php

namespace App\Modules\Scheduling\Contracts;

use App\Modules\Scheduling\Enums\SessionStatus;
use App\Support\Money;
use Carbon\CarbonInterface;

/** The few facts about a session that the Payments module is allowed to see. */
final readonly class BillableSession
{
    public function __construct(
        public string $id,
        public string $candidateId,
        public string $recruiterId,
        public SessionStatus $status,
        public Money $price,
        public ?CarbonInterface $holdExpiresAt,
        public bool $visibleToRecruiter,
    ) {}

    /** Can the candidate still pay for it? */
    public function awaitsPayment(): bool
    {
        return $this->status === SessionStatus::PendingPayment
            && $this->holdExpiresAt !== null
            && $this->holdExpiresAt->isFuture();
    }
}
