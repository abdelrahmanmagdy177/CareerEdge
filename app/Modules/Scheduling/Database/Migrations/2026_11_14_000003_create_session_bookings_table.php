<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The table is called `session_bookings`, not `sessions`: Laravel uses the name `sessions`
 * for its own browser-session table, and the two must never be confused.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_bookings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('candidate_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('recruiter_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('offering_id')->constrained('offerings')->restrictOnDelete();
            $table->foreignUuid('slot_id')->constrained('availability_slots')->restrictOnDelete();
            $table->uuid('cv_id')->nullable();
            $table->uuid('specialization_id')->nullable();

            // Copied from the offering at booking time. Later edits to the offering (a new
            // price, switching it off) must not change a session that is already booked.
            $table->string('type', 30);
            $table->string('mode', 10);
            $table->unsignedSmallInteger('duration_minutes');
            $table->bigInteger('price_minor');
            $table->char('currency', 3);

            $table->timestampTz('scheduled_start');
            $table->timestampTz('scheduled_end');
            $table->timestampTz('actual_start')->nullable();
            $table->timestampTz('actual_end')->nullable();

            $table->string('status', 20);
            $table->text('candidate_notes')->nullable();
            // Set while the session waits for payment. The slot is released when it passes.
            $table->timestampTz('hold_expires_at')->nullable();
            // Set when the payment arrives. A session that never got here was never real for
            // the recruiter, and they do not see it.
            $table->timestampTz('confirmed_at')->nullable();
            $table->string('cancelled_by', 10)->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestampsTz();

            $table->index(['candidate_id', 'scheduled_start']);
            $table->index(['recruiter_id', 'scheduled_start']);
            $table->index(['status', 'hold_expires_at']);
        });

        // Business rule 3: a candidate cannot have two sessions at the same time. Enforced by
        // the database, like the slots: two bookings sent at the same instant cannot both be
        // saved. Sessions that were cancelled, refunded or a no-show no longer count, which is
        // what the WHERE part says.
        DB::statement(<<<'SQL'
            ALTER TABLE session_bookings
            ADD CONSTRAINT session_bookings_candidate_no_overlap
            EXCLUDE USING gist (candidate_id WITH =, tstzrange(scheduled_start, scheduled_end) WITH &&)
            WHERE (status NOT IN ('cancelled', 'no_show', 'refunded'))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('session_bookings');
    }
};
