<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bidding, inDrive-style (docs/rides-plan.md §9, phase 2): the rider's price and each raise, and the
 * drivers' answers — "accept your price" or a higher offer that lives for `bids.ttl_s`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->bigInteger('bid_floor_minor')->nullable()->after('rider_offer_minor')->comment('أقل سعر يقدر الراكب يكتبه');
            $table->bigInteger('bid_suggested_minor')->nullable()->after('bid_floor_minor')->comment('السعر المقترح من التسعير');
        });

        Schema::create('ride_bids', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('ride_id')->constrained('rides')->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained('drivers')->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt')->comment('رقم محاولة السواق في الطلب ده');
            $table->string('type', 24)->comment('accept_rider_price / counter');
            $table->bigInteger('rider_price_minor')->comment('سعر الراكب وقت العرض');
            $table->bigInteger('bid_price_minor');
            $table->bigInteger('driver_net_minor')->nullable();
            $table->unsignedInteger('distance_m')->default(0);
            $table->unsignedInteger('eta_s')->default(0);
            $table->string('status', 12)->comment('queued / pending / accepted / declined / expired / withdrawn');
            $table->timestamp('shown_at')->nullable()->comment('التايمر بيبدأ من هنا');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->string('idempotency_key', 80)->nullable()->unique();
            $table->timestamps();
            $table->unique(['ride_id', 'driver_id', 'attempt']);
            $table->index(['ride_id', 'status']);
            $table->index(['status', 'expires_at']);
        });

        Schema::create('ride_price_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ride_id')->constrained('rides')->cascadeOnDelete();
            $table->bigInteger('price_minor');
            $table->bigInteger('floor_minor');
            $table->string('reason', 12)->comment('initial / raise');
            $table->timestamp('created_at')->nullable();
            $table->index('ride_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ride_price_offers');
        Schema::dropIfExists('ride_bids');
        Schema::table('rides', function (Blueprint $table) {
            $table->dropColumn(['bid_floor_minor', 'bid_suggested_minor']);
        });
    }
};
