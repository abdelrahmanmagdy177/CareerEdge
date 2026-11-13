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

    'cv' => [
        /*
        | The filesystem disk (config/filesystems.php) that holds CV files. "local" keeps them
        | in storage/app/private. Use "s3" for S3 or MinIO in production.
        */
        'disk' => env('CV_DISK', 'local'),

        /*
        | PHP has its own limits that must be at least this high, or it rejects the upload
        | first: upload_max_filesize and post_max_size in php.ini.
        */
        'max_bytes' => 5 * 1024 * 1024,

        'max_per_candidate' => 5,

        'download_url_minutes' => 5,
    ],

];
