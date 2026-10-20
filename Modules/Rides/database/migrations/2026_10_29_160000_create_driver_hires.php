<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Find a driver (docs/rides_tasks.md O-26c; LeeTaxi's «hire a driver»): the rider has a car and wants
 * someone to drive it — by the hour or by the day, now or at a time, automatic or manual. Paid upfront
 * from the wallet; the first nearby captain to accept takes it; arrived → started → completed (minutes
 * past the time booked are added), then the captain is paid less DORR's commission.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_hires', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained('drivers')->nullOnDelete();
            $table->foreignId('country_id')->constrained('countries')->restrictOnDelete();
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->nullOnDelete();
            $table->decimal('pickup_lat', 10, 7);
            $table->decimal('pickup_lng', 10, 7);
            $table->string('pickup_address', 255);
            $table->dateTime('starts_at');
            $table->string('unit', 4)->comment('hour / day');
            $table->unsignedSmallInteger('quantity');
            $table->string('transmission', 10)->default('automatic')->comment('automatic / manual');
            $table->string('car', 120)->nullable()->comment('the rider\'s car as they describe it (make, model, colour, plate)');
            $table->string('note', 255)->nullable();
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('extra_minor')->default(0)->comment('minutes past the time booked');
            $table->unsignedBigInteger('extra_due_minor')->default(0)->comment('extra the wallet could not cover');
            $table->string('status', 12)->default('open')->comment('open / accepted / arrived / started / completed / canceled / expired');
            $table->json('wallet_transaction_ids')->nullable();
            $table->string('idempotency_key', 80)->nullable();
            $table->dateTime('accepted_at')->nullable();
            $table->dateTime('arrived_at')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('ended_at')->nullable();
            $table->dateTime('canceled_at')->nullable();
            $table->string('canceled_by', 8)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['status', 'starts_at']);
            $table->index(['pickup_lat', 'pickup_lng']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_hires');
    }
};
