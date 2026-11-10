<?php

return [

    /*
    | Access tokens are short-lived. The app gets a new one with its refresh token.
    */
    'access_token_minutes' => (int) env('ACCESS_TOKEN_MINUTES', 15),

    'refresh_token_days' => (int) env('REFRESH_TOKEN_DAYS', 30),

    /*
    | Attempts per minute, per IP, on register, login and refresh.
    */
    'auth_rate_limit_per_minute' => (int) env('AUTH_RATE_LIMIT_PER_MINUTE', 10),

];
