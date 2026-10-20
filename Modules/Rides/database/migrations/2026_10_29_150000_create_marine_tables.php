<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Boats & yachts (docs/rides_tasks.md O-25; R-29, R-41, R-42, D-80, D-100..D-102). A captain with a boat
 * or a yacht publishes listings of two kinds:
 *  - a sea trip (`trip`): from a marina, a route, when it leaves and for how long — seats are sold
 *    (shared) or the whole boat (private, only while nobody shares it);
 *  - a yacht hire (`rental`): by the hour, from a marina, on the day and time the guest picks — never
 *    overlapping another hire of the same boat; the guest may ask for more time and the captain agrees.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marine_listings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('driver_id')->constrained('drivers')->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained('vehicles')->restrictOnDelete();
            $table->foreignId('country_id')->constrained('countries')->restrictOnDelete();
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->nullOnDelete();
            $table->string('kind', 8)->comment('trip / rental');
            $table->string('title', 120);
            $table->text('description')->nullable();
            $table->decimal('marina_lat', 10, 7);
            $table->decimal('marina_lng', 10, 7);
            $table->string('marina_address', 255);
            $table->string('route_note', 255)->nullable()->comment('where the trip goes (and that it comes back to the marina)');
            $table->json('amenities')->nullable()->comment('life_jackets, snorkeling, fishing, food, drinks, music, cabin, toilet, crew…');
            $table->unsignedSmallInteger('capacity');
            // A sea trip.
            $table->dateTime('depart_at')->nullable();
            $table->unsignedSmallInteger('duration_min')->nullable();
            $table->unsignedBigInteger('seat_price_minor')->nullable();
            $table->unsignedBigInteger('private_price_minor')->nullable()->comment('the whole boat for this trip');
            // A yacht hire.
            $table->unsignedBigInteger('hour_price_minor')->nullable();
            $table->unsignedTinyInteger('min_hours')->nullable();
            $table->string('status', 12)->default('active')->comment('active / paused / departed / completed / canceled');
            $table->timestamps();
            $table->index(['kind', 'status']);
            $table->index(['marina_lat', 'marina_lng']);
        });

        Schema::create('marine_bookings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('marine_listing_id')->constrained('marine_listings')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('mode', 8)->comment('shared / private / rental');
            $table->unsignedSmallInteger('guests');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('extension_minor')->default(0);
            $table->json('extension')->nullable()->comment('the last request for more time: {hours, amount_minor, status: pending|approved|declined}');
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->nullOnDelete();
            $table->string('status', 12)->default('confirmed')->comment('confirmed / boarded / completed / canceled');
            $table->string('qr_token', 48)->unique();
            $table->json('wallet_transaction_ids')->nullable();
            $table->string('idempotency_key', 80)->nullable();
            $table->dateTime('canceled_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['marine_listing_id', 'status', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marine_bookings');
        Schema::dropIfExists('marine_listings');
    }
};
