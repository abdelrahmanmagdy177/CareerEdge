<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('session_id')->constrained('session_bookings')->restrictOnDelete();
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('status', 20);
            $table->string('gateway', 20);
            // The gateway's own id for this payment. Webhooks are matched to a payment by it.
            $table->string('gateway_reference', 100);
            $table->text('checkout_url');
            $table->text('return_url')->nullable();
            $table->bigInteger('refunded_minor')->default(0);
            $table->timestampTz('held_at')->nullable();
            $table->timestampTz('release_scheduled_at')->nullable();
            $table->timestampTz('released_at')->nullable();
            $table->timestampTz('refunded_at')->nullable();
            $table->timestampsTz();

            $table->unique(['gateway', 'gateway_reference']);
            $table->index('session_id');
        });

        // At most one payment in progress per session. Two checkouts started at the same
        // instant cannot both create a payment, so a session can never be paid for twice.
        DB::statement("CREATE UNIQUE INDEX payments_one_open_per_session ON payments (session_id) WHERE status IN ('pending', 'held')");
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
