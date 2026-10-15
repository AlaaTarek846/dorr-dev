<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Driver\Enums\PresenceStatus;

/**
 * Where each driver is now, their online sessions, and the location log the phone sends every
 * 30 s (docs/rides-structure.md §1.8–1.10). Coordinates are plain decimals: the tests run on
 * SQLite, and nearby searches use a bounding box + haversine in PHP (§0.3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_presence', function (Blueprint $table) {
            $table->foreignId('driver_id')->primary()->constrained('drivers')->cascadeOnDelete();
            $table->string('status', 16)->default(PresenceStatus::Offline->value)->comment('offline / online / paused / on_trip');
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->unsignedSmallInteger('heading')->nullable()->comment('0–359');
            $table->decimal('speed_mps', 6, 2)->nullable();
            $table->unsignedSmallInteger('accuracy_m')->nullable();
            $table->unsignedBigInteger('city_id')->nullable()->comment('المدينة اللي هو فيها');
            $table->boolean('gps_enabled')->default(true);
            $table->timestamp('gps_off_since')->nullable();
            $table->boolean('last_ride_requested')->default(false)->comment('آخر رحلة');
            $table->timestamp('paused_until')->nullable()->comment('الاستراحة');
            $table->timestamp('online_since')->nullable();
            $table->timestamp('last_seen_at')->nullable()->comment('آخر نقطة وصلت');
            $table->string('app_version', 20)->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->index(['status', 'lat']);
            $table->index(['status', 'city_id']);
            $table->index('last_seen_at');
        });

        Schema::create('driver_presence_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('drivers')->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason', 16)->nullable()->comment('manual / stale / gps_off / last_ride / admin / suspended');
            $table->index(['driver_id', 'started_at']);
        });

        Schema::create('driver_location_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('drivers')->cascadeOnDelete();
            $table->uuid('batch_uuid')->comment('من الموبايل — منع التكرار');
            $table->unsignedSmallInteger('points_count');
            $table->timestamp('first_recorded_at', 3)->nullable();
            $table->timestamp('last_recorded_at', 3)->nullable();
            $table->integer('clock_offset_ms')->default(0)->comment('فرق ساعة الموبايل عن السيرفر');
            $table->timestamp('received_at')->useCurrent();
            $table->unique(['driver_id', 'batch_uuid']);
        });

        // Append-only (evidence for disputes and for Wasl): no updated_at, no cascade from the driver.
        Schema::create('driver_location_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('drivers')->restrictOnDelete();
            $table->unsignedBigInteger('ride_id')->nullable()->comment('الرحلة لو فيه — من غير FK');
            $table->foreignId('batch_id')->constrained('driver_location_batches')->restrictOnDelete();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->unsignedSmallInteger('accuracy_m')->nullable();
            $table->decimal('speed_mps', 6, 2)->nullable();
            $table->unsignedSmallInteger('heading')->nullable();
            $table->timestamp('recorded_at', 3)->comment('الوقت الحقيقي');
            $table->timestamp('device_time', 3)->nullable()->comment('وقت الموبايل للمقارنة');
            $table->boolean('has_customer')->default(false)->comment('لوصل');
            $table->boolean('is_mock')->default(false)->comment('موقع وهمي');
            $table->timestamp('wasl_sent_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['driver_id', 'recorded_at']);
            $table->index(['ride_id', 'recorded_at']);
            $table->index(['wasl_sent_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_location_log');
        Schema::dropIfExists('driver_location_batches');
        Schema::dropIfExists('driver_presence_sessions');
        Schema::dropIfExists('driver_presence');
    }
};
