<?php

namespace Tests\Unit;

use App\Support\Http\ApiDate;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class ApiDateTest extends TestCase
{
    public function test_it_converts_any_timezone_to_utc_with_a_trailing_z(): void
    {
        $cairo = CarbonImmutable::parse('2026-11-10 20:00:00', 'Africa/Cairo');

        $this->assertSame('2026-11-10T18:00:00Z', ApiDate::format($cairo));
    }

    public function test_it_drops_fractions_of_a_second(): void
    {
        $date = CarbonImmutable::parse('2026-11-10T18:00:00.987654Z');

        $this->assertSame('2026-11-10T18:00:00Z', ApiDate::format($date));
    }

    public function test_it_does_not_change_the_date_it_was_given(): void
    {
        $date = CarbonImmutable::parse('2026-11-10 20:00:00', 'Africa/Cairo');

        ApiDate::format($date);

        $this->assertSame('Africa/Cairo', $date->timezoneName);
    }
}
