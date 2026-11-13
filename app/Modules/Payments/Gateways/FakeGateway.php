<?php

namespace App\Modules\Payments\Gateways;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * A pretend payment provider for development and tests. No money moves.
 *
 * It behaves like a real one where it matters: checkout returns a page to open, and the
 * result arrives later as a signed webhook. The "pay" page is served by this app itself
 * (FakeCheckoutController). It must never be used in production: see PaymentsServiceProvider.
 */
final class FakeGateway implements PaymentGateway
{
    public const SIGNATURE_HEADER = 'X-Signature';

    /** @var list<array{reference: string, amountMinor: int}> refunds made, so tests can check them */
    public array $refunds = [];

    public function __construct(private readonly string $webhookSecret) {}

    public function name(): string
    {
        return 'fake';
    }

    public function startCheckout(string $paymentId, Money $amount, ?string $returnUrl): CheckoutStarted
    {
        $reference = 'fake_'.Str::random(24);

        return new CheckoutStarted($reference, url("/fake-gateway/checkout/$reference"));
    }

    public function readWebhook(Request $request): WebhookEvent
    {
        $body = $request->getContent();

        // hash_equals compares in constant time. A plain === returns sooner the earlier the
        // strings differ, and that timing can be measured to guess a signature byte by byte.
        if (! hash_equals($this->sign($body), (string) $request->header(self::SIGNATURE_HEADER))) {
            throw new InvalidWebhookSignature;
        }

        $data = json_decode($body, true);

        return new WebhookEvent(
            reference: (string) ($data['reference'] ?? ''),
            succeeded: ($data['status'] ?? null) === 'succeeded',
            amount: new Money((int) ($data['amountMinor'] ?? 0), (string) ($data['currency'] ?? Money::DEFAULT_CURRENCY)),
        );
    }

    public function refund(string $gatewayReference, Money $amount): void
    {
        $this->refunds[] = ['reference' => $gatewayReference, 'amountMinor' => $amount->amountMinor];
    }

    /** The signature a real provider would compute with the secret it shares with us. */
    public function sign(string $body): string
    {
        return hash_hmac('sha256', $body, $this->webhookSecret);
    }
}
