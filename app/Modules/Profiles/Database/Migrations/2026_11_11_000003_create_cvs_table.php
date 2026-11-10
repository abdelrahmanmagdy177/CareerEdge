<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cvs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('file_name', 255);
            $table->string('content_type', 100);
            $table->unsignedInteger('size_bytes');
            // Where the file is on the storage disk. A random name, never the name the user gave.
            $table->string('storage_key', 255)->unique();
            $table->timestampsTz();
            // Soft delete: the row and the file stay, because a recruiter may still need the CV
            // of a session that already happened (business rule 10 in docs/PLAN.md).
            $table->softDeletesTz();

            $table->index(['user_id', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cvs');
    }
};
