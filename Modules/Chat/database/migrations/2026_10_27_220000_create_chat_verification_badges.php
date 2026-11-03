<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Account verification, batch 2 (docs/verification_tasks.md AV-020 – AV-025): the badge of each account —
 * one row, its status apart from the request's — and every change made to it (who, from, to, why).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_verification_badges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('status', 12)->comment('temporary · active · suspended · expired · withdrawn');
            $table->string('type', 16)->default('standard')->comment('standard · celebrity');
            $table->foreignId('request_id')->nullable()->constrained('chat_verification_requests')->nullOnDelete();
            $table->timestamp('active_from')->nullable();
            $table->timestamp('expires_at')->nullable()->comment('null = no end set yet');
            $table->string('suspended_from', 12)->nullable();
            $table->string('reason', 1000)->nullable()->comment('the last change\'s reason, as the account reads it');
            $table->timestamps();

            $table->index(['status', 'expires_at']);
        });

        Schema::create('chat_verification_badge_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('badge_id')->constrained('chat_verification_badges')->cascadeOnDelete();
            $table->string('from_status', 12)->nullable();
            $table->string('to_status', 12);
            $table->string('actor_type', 8)->comment('admin · system');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('reason', 1000)->nullable();
            $table->json('changes')->nullable()->comment('type / expires_at before and after');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_verification_badge_events');
        Schema::dropIfExists('chat_verification_badges');
    }
};
