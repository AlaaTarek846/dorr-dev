<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Something left in the car (docs/rides_tasks.md D-294..D-297): the rider reports it on the ride, the captain answers
 * (found / not found), they agree on how it comes back through messages here — never their numbers — and the
 * captain records the return with a photo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ride_lost_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('ride_id')->constrained('rides')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained('drivers')->nullOnDelete();
            $table->string('description', 500);
            $table->string('status', 12)->default('reported')->comment('reported / found / not_found / returned / closed');
            $table->string('return_plan', 300)->nullable()->comment('where / when they meet');
            $table->string('return_photo_path')->nullable();
            $table->dateTime('answered_at')->nullable();
            $table->dateTime('returned_at')->nullable();
            $table->timestamps();
            $table->index(['driver_id', 'status']);
        });
        Schema::create('ride_lost_item_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ride_lost_item_id')->constrained('ride_lost_items')->cascadeOnDelete();
            $table->string('sender', 8)->comment('user / driver');
            $table->string('body', 1000);
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ride_lost_item_messages');
        Schema::dropIfExists('ride_lost_items');
    }
};
