<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offerings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('recruiter_id')->constrained('users')->cascadeOnDelete();
            $table->string('session_type', 30);
            $table->string('mode', 10);
            $table->unsignedSmallInteger('duration_minutes');
            // Money is whole minor units (piasters) in a bigint, never a decimal or float.
            $table->bigInteger('price_minor');
            $table->char('currency', 3);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index(['recruiter_id', 'is_active']);
        });

        // "Only one ACTIVE offering per recruiter, type, mode and duration", enforced by the
        // database. A partial unique index only looks at rows where is_active is true, so old
        // inactive copies do not get in the way. Laravel's schema builder has no method for
        // partial indexes, so this is plain SQL.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX offerings_one_active
            ON offerings (recruiter_id, session_type, mode, duration_minutes)
            WHERE is_active
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('offerings');
    }
};
