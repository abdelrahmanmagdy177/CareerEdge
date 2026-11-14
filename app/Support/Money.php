<?php

namespace App\Support;

/**
 * An amount of money, the way the contract carries it: a whole number of minor units
 * (piasters) plus the currency. 45000 EGP-minor is 450.00 EGP.
 *
 * Never a float. 0.1 + 0.2 is not 0.3 in floating point, and money must add up exactly.
 */
final readonly class Money
{
    /** The only currency for now. The type still carries one so adding more is not a rewrite. */
    public const DEFAULT_CURRENCY = 'EGP';

    public function __construct(public int $amountMinor, public string $currency = self::DEFAULT_CURRENCY) {}

    /** The same currency with another amount. */
    public function withAmount(int $amountMinor): self
    {
        return new self($amountMinor, $this->currency);
    }

    /** @return array{amountMinor: int, currency: string} */
    public function toArray(): array
    {
        return ['amountMinor' => $this->amountMinor, 'currency' => $this->currency];
    }
}
