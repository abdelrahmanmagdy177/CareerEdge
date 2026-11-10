<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A profile's primary key is the user's id: one profile per user, no extra id to carry around.
 *
 * `user_id` has a database foreign key to Identity's `users` table. That is the one place this
 * module touches another module's table: it keeps the data consistent (no profile without a
 * user) but the PHP code here never queries `users`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_profiles', function (Blueprint $table) {
            $table->foreignUuid('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->string('headline', 150)->nullable();
            $table->foreignUuid('specialization_id')->constrained('specializations')->restrictOnDelete();
            $table->string('target_role', 100);
            $table->string('target_level', 10)->nullable();
            $table->unsignedSmallInteger('years_of_experience');
            $table->timestampsTz();
        });

        Schema::create('recruiter_profiles', function (Blueprint $table) {
            $table->foreignUuid('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->text('bio');
            $table->unsignedSmallInteger('years_of_experience');
            $table->string('current_title', 100)->nullable();
            $table->string('linkedin_url', 2048)->nullable();
            $table->jsonb('languages');
            $table->string('timezone', 64);
            $table->string('verification_status', 10)->index();
            $table->string('verification_note', 1000)->nullable();
            // Kept here so search can sort by rating without counting reviews on every request.
            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->unsignedInteger('rating_count')->default(0);
            $table->unsignedInteger('cancellation_count')->default(0);
            $table->timestampsTz();
        });

        Schema::create('recruiter_specializations', function (Blueprint $table) {
            $table->foreignUuid('recruiter_id')->constrained('recruiter_profiles', 'user_id')->cascadeOnDelete();
            $table->foreignUuid('specialization_id')->constrained('specializations')->restrictOnDelete();
            $table->primary(['recruiter_id', 'specialization_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruiter_specializations');
        Schema::dropIfExists('recruiter_profiles');
        Schema::dropIfExists('candidate_profiles');
    }
};
