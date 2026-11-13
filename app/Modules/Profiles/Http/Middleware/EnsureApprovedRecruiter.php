<?php

namespace App\Modules\Profiles\Http\Middleware;

use App\Modules\Profiles\Contracts\RecruiterVerification;
use App\Support\Http\ApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a recruiter through only once an admin has approved their profile.
 * Use on routes as `recruiter.approved`, after `auth:sanctum` and `role:recruiter`.
 */
final class EnsureApprovedRecruiter
{
    public function __construct(private readonly RecruiterVerification $verification) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->verification->isApproved($request->user()->id)) {
            throw ApiError::forbidden('RECRUITER_NOT_APPROVED', 'Recruiter is not approved yet');
        }

        return $next($request);
    }
}
