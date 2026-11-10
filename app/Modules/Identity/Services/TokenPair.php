<?php

namespace App\Modules\Identity\Services;

use Carbon\CarbonInterface;

final readonly class TokenPair
{
    public function __construct(
        public string $accessToken,
        public CarbonInterface $accessTokenExpiresAt,
        public string $refreshToken,
        public CarbonInterface $refreshTokenExpiresAt,
    ) {}
}
