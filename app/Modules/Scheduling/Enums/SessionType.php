<?php

namespace App\Modules\Scheduling\Enums;

enum SessionType: string
{
    case MockInterview = 'mock_interview';
    case CvReview = 'cv_review';
    case SalaryConsultation = 'salary_consultation';
}
