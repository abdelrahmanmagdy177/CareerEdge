<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // unique: one review per session, enforced by the database (business rule 9).
            $table->foreignUuid('session_id')->unique()->constrained('session_bookings')->cascadeOnDelete();
            $table->foreignUuid('candidate_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('recruiter_id')->constrained('users')->restrictOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->string('comment', 1000)->nullable();
            $table->timestampsTz();

            $table->index(['recruiter_id', 'id']);
        });

        DB::statement('ALTER TABLE reviews ADD CONSTRAINT reviews_rating_range CHECK (rating BETWEEN 1 AND 5)');

        Schema::create('disputes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // unique: one dispute per session.
            $table->foreignUuid('session_id')->unique()->constrained('session_bookings')->cascadeOnDelete();
            $table->foreignUuid('opened_by')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->string('status', 20)->index();
            $table->text('resolution_note')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disputes');
        Schema::dropIfExists('reviews');
    }
};
