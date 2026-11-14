<?php

namespace App\Modules\Scheduling\Voice;

use Carbon\CarbonInterface;

/**
 * The voice call service. We never run calls ourselves: the app connects straight to the
 * provider, and all this API does is hand out a pass that lets one user into one room.
 */
interface VoiceProvider
{
    /**
     * @return array{provider: string, serverUrl: string, roomName: string, token: string, expiresAt: string}
     *                                                                                                        the `voice` object of the contract's JoinResponse
     */
    public function access(string $roomName, string $userId, string $displayName, CarbonInterface $expiresAt): array;
}
