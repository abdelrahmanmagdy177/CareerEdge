<?php

namespace App\Modules\Payments;

use App\Modules\Payments\Console\ReleaseDuePayments;
use App\Modules\Payments\Gateways\FakeGateway;
use App\Modules\Payments\Gateways\PaymentGateway;
use App\Modules\Payments\Http\Controllers\FakeCheckoutController;
use App\Modules\Payments\Listeners\SettleOnSessionEvents;
use App\Modules\Payments\Services\PaymentStatuses;
use App\Modules\Scheduling\Contracts\PaymentStatusLookup;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use LogicException;

class PaymentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One gateway object for the whole request. Which class it is comes from config.
        $this->app->singleton(PaymentGateway::class, function () {
            $name = config('careeredge.payments.gateway');

            if ($name !== 'fake') {
                throw new LogicException("Unknown payment gateway [$name]. Only \"fake\" exists so far.");
            }

            // With the fake gateway every session would be free. Refuse to take payments at all
            // rather than let that reach real users.
            if ($this->app->isProduction()) {
                throw new LogicException('The fake payment gateway cannot be used in production. Set PAYMENT_GATEWAY to a real gateway.');
            }

            return new FakeGateway(config('careeredge.payments.fake_webhook_secret'));
        });

        // Gives Scheduling the payment status it shows on a session.
        $this->app->bind(PaymentStatusLookup::class, PaymentStatuses::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        // Refunds, payouts and freezes, in reaction to what happens to a session.
        Event::subscribe(SettleOnSessionEvents::class);

        $this->commands([ReleaseDuePayments::class]);
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command(ReleaseDuePayments::class)->everyMinute()->withoutOverlapping();
        });

        if (config('careeredge.payments.gateway') === 'fake' && ! $this->app->isProduction()) {
            Route::get('/fake-gateway/checkout/{reference}', [FakeCheckoutController::class, 'show']);
            Route::post('/fake-gateway/checkout/{reference}', [FakeCheckoutController::class, 'pay']);
        }
    }
}
