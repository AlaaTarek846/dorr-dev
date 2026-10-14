<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The rider ↔ driver chat of one ride (owner, 2026-10-13): open while a driver is on the ride,
 * closed when it ends — and kept, so the admin can review it when there's a problem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ride_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ride_id')->constrained('rides')->cascadeOnDelete();
            $table->string('sender_type', 8)->comment('user / driver');
            $table->unsignedBigInteger('sender_id');
            $table->string('kind', 8)->default('text')->comment('text / quick');
            $table->string('quick_key', 32)->nullable()->comment('رسالة جاهزة');
            $table->text('body');
            $table->string('client_id', 64)->nullable()->comment('لو نفس الرسالة اتبعتت مرتين');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['ride_id', 'id']);
            $table->unique(['ride_id', 'sender_type', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ride_messages');
    }
};
