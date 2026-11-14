<?php

namespace App\Modules\Scheduling\Voice;

use App\Support\Http\ApiDate;
use Carbon\CarbonInterface;

/**
 * LiveKit access tokens. A LiveKit token is a standard JWT signed with the project's API
 * secret (HS256), carrying a `video` grant that names the one room the holder may join.
 * It is built here by hand: three base64url parts and one HMAC, no SDK needed.
 */
final class LiveKitVoiceProvider implements VoiceProvider
{
    public function __construct(
        private readonly string $serverUrl,
        private readonly string $apiKey,
        private readonly string $apiSecret,
    ) {}

    public function access(string $roomName, string $userId, string $displayName, CarbonInterface $expiresAt): array
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $claims = [
            'iss' => $this->apiKey,
            // The identity LiveKit shows for this participant. One user, one identity.
            'sub' => $userId,
            'name' => $displayName,
            'nbf' => now()->getTimestamp(),
            'exp' => $expiresAt->getTimestamp(),
            'video' => [
                'room' => $roomName,
                'roomJoin' => true,
                'canPublish' => true,
                'canSubscribe' => true,
            ],
        ];

        $unsigned = $this->encode($header).'.'.$this->encode($claims);
        $signature = $this->base64Url(hash_hmac('sha256', $unsigned, $this->apiSecret, binary: true));

        return [
            'provider' => 'livekit',
            'serverUrl' => $this->serverUrl,
            'roomName' => $roomName,
            'token' => "$unsigned.$signature",
            'expiresAt' => ApiDate::format($expiresAt),
        ];
    }

    /** @param  array<string, mixed>  $data */
    private function encode(array $data): string
    {
        return $this->base64Url(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** JWTs use the URL-safe base64 alphabet, without the "=" padding. */
    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
