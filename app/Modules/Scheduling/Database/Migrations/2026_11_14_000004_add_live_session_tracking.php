<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Who actually showed up. One row per participant, written the first time they join.
        // The no-show rule (business rule 7) is decided from these rows.
        Schema::create('session_attendances', function (Blueprint $table) {
            $table->foreignUuid('session_id')->constrained('session_bookings')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestampTz('first_joined_at');

            $table->primary(['session_id', 'user_id']);
        });

        Schema::table('session_bookings', function (Blueprint $table) {
            // Set when the "5 minutes left" event was sent, so it is sent only once.
            $table->timestampTz('ending_soon_notified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('session_bookings', fn (Blueprint $table) => $table->dropColumn('ending_soon_notified_at'));
        Schema::dropIfExists('session_attendances');
    }
};
