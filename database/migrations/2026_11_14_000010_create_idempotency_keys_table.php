<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->uuid('user_id');
            $table->uuid('key');
            // A hash of the request (method, path, body), to notice a key reused for something else.
            $table->char('fingerprint', 64);
            $table->unsignedSmallInteger('response_status');
            $table->text('response_body');
            $table->timestampTz('created_at');

            $table->unique(['user_id', 'key']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
