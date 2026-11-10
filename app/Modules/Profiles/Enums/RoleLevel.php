<?php

namespace App\Modules\Profiles\Enums;

enum RoleLevel: string
{
    case Intern = 'intern';
    case Junior = 'junior';
    case Mid = 'mid';
    case Senior = 'senior';
    case Lead = 'lead';
}
