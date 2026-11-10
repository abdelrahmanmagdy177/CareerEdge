<?php

namespace App\Modules\Profiles\Enums;

/** Where a recruiter stands with the admins. Only `approved` recruiters can be found and booked. */
enum VerificationStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
