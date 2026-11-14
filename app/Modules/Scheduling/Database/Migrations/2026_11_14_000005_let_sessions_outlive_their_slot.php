<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A cancelled session gives its slot back, and the recruiter may then delete that slot.
 * The old session still pointed at it, and the database refused the delete (a 500 for the
 * recruiter). A session carries its own start and end time, so it does not need the slot
 * afterwards: when the slot is deleted, the session's `slot_id` simply becomes null.
 *
 * A slot that is held or booked still cannot be deleted. That rule is in SlotController.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('session_bookings', function (Blueprint $table) {
            $table->dropForeign(['slot_id']);
            $table->uuid('slot_id')->nullable()->change();
            $table->foreign('slot_id')->references('id')->on('availability_slots')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('session_bookings', function (Blueprint $table) {
            $table->dropForeign(['slot_id']);
            $table->foreign('slot_id')->references('id')->on('availability_slots')->restrictOnDelete();
        });
    }
};
