<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('availability_slots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('recruiter_id')->constrained('users')->cascadeOnDelete();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('status', 10)->default('open');
            $table->timestampsTz();

            $table->index(['recruiter_id', 'status', 'starts_at']);
        });

        DB::statement('ALTER TABLE availability_slots ADD CONSTRAINT availability_slots_end_after_start CHECK (ends_at > starts_at)');

        // A recruiter's slots must never overlap. The app checks this before saving, but two
        // requests at the same moment can both pass that check. This constraint is the real
        // guarantee: PostgreSQL itself refuses a row whose time range overlaps (&&) another
        // row of the same recruiter. Ranges are [start, end), so one slot may begin exactly
        // when the previous one ends.
        //
        // btree_gist is the standard extension that lets the "same recruiter" part (=) share
        // an index with the range part.
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
        DB::statement(<<<'SQL'
            ALTER TABLE availability_slots
            ADD CONSTRAINT availability_slots_no_overlap
            EXCLUDE USING gist (recruiter_id WITH =, tstzrange(starts_at, ends_at) WITH &&)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('availability_slots');
    }
};
