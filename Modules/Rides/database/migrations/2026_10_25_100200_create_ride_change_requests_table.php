<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Changing the trip while it runs (R-91..95, D-131..136, docs/rides-structure.md §3.10): the rider
 * sees what a new destination (or an extra stop) does to the distance, the time and the price, sends
 * it, and the captain accepts or declines within `change.driver_response_s`. Applied, the ride's
 * route and fixed fare change — the old numbers stay here and in `ride_events` (append-only).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ride_change_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('ride_id')->constrained('rides')->cascadeOnDelete();
            $table->string('type', 16)->comment('destination / add_stop');
            $table->string('requested_by', 8)->default('user');
            $table->json('payload')->comment('النقطة الجديدة: lat / lng / address');
            $table->json('route')->nullable()->comment('distance_m / duration_s / polyline للمسار الجديد');
            $table->integer('distance_delta_m')->default(0);
            $table->integer('duration_delta_s')->default(0);
            $table->bigInteger('price_delta_minor')->default(0)->comment('بإشارة');
            $table->bigInteger('new_total_minor')->nullable();
            $table->bigInteger('driver_net_delta_minor')->default(0);
            $table->json('breakdown')->nullable()->comment('أرقام السعر الجديد: fare / commission / tax / driver_net / total');
            $table->timestamp('rider_consent_at')->nullable();
            $table->string('driver_response', 16)->default('pending')->comment('pending / accepted / rejected / expired');
            $table->timestamp('driver_responded_at')->nullable();
            $table->string('status', 12)->default('preview')->comment('preview / pending / applied / rejected / expired / canceled');
            $table->string('result_code', 32)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['ride_id', 'status']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ride_change_requests');
    }
};
