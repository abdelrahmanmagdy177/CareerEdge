<?php

namespace App\Modules\Scheduling\Models;

use App\Modules\Scheduling\Enums\SessionMode;
use App\Modules\Scheduling\Enums\SessionType;
use App\Support\Money;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A service a recruiter sells: a session type, how it is held, how long, and the price.
 *
 * @property string $id
 * @property string $recruiter_id
 * @property SessionType $session_type
 * @property SessionMode $mode
 * @property int $duration_minutes
 * @property int $price_minor
 * @property string $currency
 * @property bool $is_active
 */
class Offering extends Model
{
    use HasUuids;

    public const DURATIONS = [30, 45, 60];

    public const MIN_PRICE_MINOR = 5_000;

    public const MAX_PRICE_MINOR = 1_000_000;

    protected $fillable = ['recruiter_id', 'session_type', 'mode', 'duration_minutes', 'price_minor', 'currency', 'is_active'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return [
            'session_type' => SessionType::class,
            'mode' => SessionMode::class,
            'is_active' => 'boolean',
        ];
    }

    public function price(): Money
    {
        return new Money($this->price_minor, $this->currency);
    }
}
