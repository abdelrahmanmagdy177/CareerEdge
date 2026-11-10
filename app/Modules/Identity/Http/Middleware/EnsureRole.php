 <?php

namespace App\Modules\Identity\Http\Middleware;

use App\Support\Http\ApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a request through only if the logged-in user has one of the given roles.
 * Use on routes as `role:candidate` or `role:recruiter,admin`, after `auth:sanctum`.
 */
final class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (! in_array($request->user()?->role->value, $roles, true)) {
            throw ApiError::forbidden('FORBIDDEN_ROLE', 'This action needs another role');
        }

        return $next($request);
    }
}
