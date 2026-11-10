<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Throwable;

/** GET /health: says whether the app can reach its database. Used by Docker and uptime checks. */
final class HealthController
{
    public function __invoke(): Response
    {
        try {
            DB::connection()->getPdo();
        } catch (Throwable) {
            return response('Unhealthy', 503);
        }

        return response('Healthy');
    }
}
