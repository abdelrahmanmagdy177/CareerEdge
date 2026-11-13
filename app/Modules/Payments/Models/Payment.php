<?php

namespace App\Modules\Payments\Models;

use App\Modules\Payments\Enums\PaymentStatus;
use App\Support\Money;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $session_id
 * @property int $amount_minor
 * @property string $currency
 * @property PaymentStatus $status
 * @property string $gateway
 * @property string $gateway_reference
 * @property string $checkout_url
 * @property string|null $return_url
 * @property int $refunded_minor
 * @property Carbon|null $held_at
 * @property Carbon|null $release_scheduled_at
 * @property Carbon|null $released_at
 * @property Carbon|null $refunded_at
 */
class Payment extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $attributes = ['refunded_minor' => 0];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'held_at' => 'datetime',
            'release_scheduled_at' => 'datetime',
            'released_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    public function amount(): Money
    {
        return new Money($this->amount_minor, $this->currency);
    }

    public function refunded(): Money
    {
        return new Money($this->refunded_minor, $this->currency);
    }
}
