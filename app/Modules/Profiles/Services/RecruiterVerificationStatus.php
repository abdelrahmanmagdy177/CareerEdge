<?php

namespace App\Modules\Profiles\Services;

use App\Modules\Profiles\Contracts\RecruiterVerification;
use App\Modules\Profiles\Enums\VerificationStatus;
use App\Modules\Profiles\Models\RecruiterProfile;

final class RecruiterVerificationStatus implements RecruiterVerification
{
    public function isApproved(string $userId): bool
    {
        return RecruiterProfile::whereKey($userId)
            ->where('verification_status', VerificationStatus::Approved)
            ->exists();
    }
}
