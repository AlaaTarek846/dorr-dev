<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Bus booking (docs/rides_tasks.md O-20..O-24) — a service, not a category of instant rides:
 *  - the captain registers a bus (a vehicle with body type bus / minibus): every seat has its number and
 *    a QR label;
 *  - he publishes trips: from where to where, when it leaves and when it comes back, and three prices —
 *    going, coming back, going and back;
 *  - riders find trips by where they leave from, where they go, or both, pick their seats like a plane
 *    ticket for going, coming back or both, and pay from their wallet;
 *  - a seat is held by exactly one ticket per leg (the unique key below); a cancelled ticket frees it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bus_trips', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('driver_id')->constrained('drivers')->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained('vehicles')->restrictOnDelete();
            $table->foreignId('country_id')->constrained('countries')->restrictOnDelete();
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->nullOnDelete();
            $table->decimal('origin_lat', 10, 7);
            $table->decimal('origin_lng', 10, 7);
            $table->string('origin_address', 255);
            $table->decimal('destination_lat', 10, 7);
            $table->decimal('destination_lng', 10, 7);
            $table->string('destination_address', 255);
            $table->dateTime('depart_at');
            $table->dateTime('return_at')->nullable()->comment('null = one way only');
            $table->unsignedBigInteger('price_outbound_minor');
            $table->unsignedBigInteger('price_return_minor')->nullable();
            $table->unsignedBigInteger('price_round_minor')->nullable()->comment('a seat for going and back');
            $table->unsignedSmallInteger('seats_total');
            $table->unsignedTinyInteger('seats_per_row')->default(4)->comment('the seat map: 2 + aisle + 2');
            $table->string('status', 16)->default('scheduled')->comment('scheduled / departed / returning / completed / canceled');
            $table->string('note', 255)->nullable();
            $table->dateTime('departed_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('canceled_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'depart_at']);
            $table->index(['origin_lat', 'origin_lng']);
            $table->index(['destination_lat', 'destination_lng']);
        });

        Schema::create('bus_bookings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('bus_trip_id')->constrained('bus_trips')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('trip_type', 12)->comment('outbound / return / round');
            $table->json('seats')->comment('{outbound: [n…], return: [n…]} — kept after a cancellation');
            $table->unsignedBigInteger('amount_minor');
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->nullOnDelete();
            $table->string('status', 12)->default('confirmed')->comment('confirmed / canceled / completed');
            $table->json('wallet_transaction_ids')->nullable();
            $table->string('idempotency_key', 80)->nullable();
            $table->dateTime('canceled_at')->nullable();
            $table->string('cancel_reason', 32)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['bus_trip_id', 'status']);
        });

        Schema::create('bus_booking_seats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bus_booking_id')->constrained('bus_bookings')->cascadeOnDelete();
            $table->foreignId('bus_trip_id')->constrained('bus_trips')->cascadeOnDelete();
            $table->string('leg', 8)->comment('outbound / return');
            $table->unsignedSmallInteger('seat_no');
            $table->string('qr_token', 48)->unique()->comment('the ticket the captain scans at boarding');
            $table->dateTime('boarded_at')->nullable();
            $table->unique(['bus_trip_id', 'leg', 'seat_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bus_booking_seats');
        Schema::dropIfExists('bus_bookings');
        Schema::dropIfExists('bus_trips');
    }
};
