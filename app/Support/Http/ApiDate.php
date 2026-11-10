<?php

namespace App\Support\Http;

use Carbon\CarbonInterface;

final class ApiDate
{
    /**
     * The one timestamp format of the contract: UTC, whole seconds, trailing "Z"
     * (2026-11-10T18:00:00Z). Database columns also keep whole seconds, so a value
     * looks the same before it is saved and after it is read back.
     */
    public static function format(CarbonInterface $date): string
    {
        return $date->copy()->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
