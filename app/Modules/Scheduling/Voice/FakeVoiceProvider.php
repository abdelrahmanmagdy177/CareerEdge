<?php

namespace App\Modules\Scheduling\Voice;

use App\Support\Http\ApiDate;
use Carbon\CarbonInterface;

/** For development and tests: the right shape, but the token opens no real call. */
final class FakeVoiceProvider implements VoiceProvider
{
    public function access(string $roomName, string $userId, string $displayName, CarbonInterface $expiresAt): array
    {
        return [
            'provider' => 'livekit',
            'serverUrl' => 'wss://voice.invalid',
            'roomName' => $roomName,
            'token' => "fake-voice-token.$roomName.$userId",
            'expiresAt' => ApiDate::format($expiresAt),
        ];
    }
}
