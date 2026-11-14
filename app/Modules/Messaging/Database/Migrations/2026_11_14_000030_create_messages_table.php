<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            // A time-ordered UUID. The chat history is sorted and paged by this id.
            $table->uuid('id')->primary();
            $table->foreignUuid('session_id')->constrained('session_bookings')->cascadeOnDelete();
            $table->foreignUuid('sender_id')->constrained('users')->restrictOnDelete();
            // Made up by the app before sending. If the app sends the same message twice
            // (a retry on a bad connection), this is how we know it is the same one.
            $table->uuid('client_message_id');
            $table->text('body');
            $table->timestampTz('created_at');

            $table->unique(['session_id', 'sender_id', 'client_message_id']);
            $table->index(['session_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
