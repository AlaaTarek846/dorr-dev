<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Driving lessons (docs/rides_tasks.md O-26b; LeeTaxi's driving school): a captain teaches. He publishes
 * lesson packs (how many lessons, how long each, automatic or manual, the price, where he teaches); a
 * learner buys a pack from the wallet and books each lesson at a time that suits them; the trainer accepts
 * it, and when it's done he's paid that lesson's share. Lessons never taken are refunded if the learner stops.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_packs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('driver_id')->constrained('drivers')->cascadeOnDelete();
            $table->foreignId('country_id')->constrained('countries')->restrictOnDelete();
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->nullOnDelete();
            $table->string('title', 120);
            $table->text('description')->nullable();
            $table->string('transmission', 10)->default('automatic')->comment('automatic / manual');
            $table->unsignedSmallInteger('lessons');
            $table->unsignedSmallInteger('minutes_per_lesson');
            $table->unsignedBigInteger('price_minor')->comment('the whole pack');
            $table->decimal('area_lat', 10, 7);
            $table->decimal('area_lng', 10, 7);
            $table->string('area_address', 255);
            $table->boolean('own_car')->default(true)->comment('the trainer brings a training car');
            $table->string('status', 10)->default('active')->comment('active / paused');
            $table->timestamps();
            $table->index(['status', 'country_id']);
        });

        Schema::create('lesson_enrollments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('lesson_pack_id')->constrained('lesson_packs')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained('drivers')->cascadeOnDelete();
            $table->unsignedSmallInteger('lessons_total');
            $table->unsignedSmallInteger('lessons_done')->default(0);
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('refunded_minor')->default(0);
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->nullOnDelete();
            $table->string('status', 10)->default('active')->comment('active / completed / canceled');
            $table->json('wallet_transaction_ids')->nullable();
            $table->string('idempotency_key', 80)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'idempotency_key']);
        });

        Schema::create('lesson_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('lesson_enrollment_id')->constrained('lesson_enrollments')->cascadeOnDelete();
            $table->unsignedSmallInteger('number')->comment('lesson 1, 2, …');
            $table->dateTime('starts_at');
            $table->decimal('pickup_lat', 10, 7);
            $table->decimal('pickup_lng', 10, 7);
            $table->string('pickup_address', 255);
            $table->string('status', 10)->default('requested')->comment('requested / confirmed / done / declined / canceled');
            $table->dateTime('done_at')->nullable();
            $table->timestamps();
            $table->index(['lesson_enrollment_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_sessions');
        Schema::dropIfExists('lesson_enrollments');
        Schema::dropIfExists('lesson_packs');
    }
};
