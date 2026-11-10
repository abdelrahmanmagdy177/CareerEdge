<?php

namespace App\Support\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Picks the response language from the `Accept-Language` header: `ar` or `en`.
 * Anything else, or no header, means English (the first entry of the list).
 */
final class SetLocaleFromHeader
{
    public const SUPPORTED = ['en', 'ar'];

    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale($request->getPreferredLanguage(self::SUPPORTED));

        return $next($request);
    }
}
