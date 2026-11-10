<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('specializations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug', 60)->unique();
            $table->string('name_en', 100);
            $table->string('name_ar', 100);
            $table->uuid('parent_id')->nullable()->index();
            $table->timestampsTz();
        });

        // Added after the table exists, because it points at the table's own primary key.
        Schema::table('specializations', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('specializations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('specializations');
    }
};
