<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('support_ticket_id')->nullable()->constrained('support_tickets')->cascadeOnDelete();
            $table->string('sender', 20)->default('user');
            $table->text('body');
            $table->timestamps();

            $table->index(['user_id', 'support_ticket_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_messages');
    }
};
