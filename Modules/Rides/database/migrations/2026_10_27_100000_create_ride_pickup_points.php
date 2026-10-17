<?php

use App\Enums\Status;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 4 of docs/rides_tasks.md — approved pickup points for sensitive places (R-16, D-111) and the
 * airport cases (R-17, R-18):
 *  - ride_pickup_points (+ translations): the gates / terminals / entrances / parkings of a zone;
 *  - ride_quotes / rides `pickup_point`, `dropoff_point`: the point the rider chose, frozen with the
 *    booking (its names in both languages, so each side reads it in their own).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ride_pickup_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('zone_id')->constrained('ride_zones')->cascadeOnDelete();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->string('kind', 16)->comment('gate / terminal / entrance / parking');
            $table->boolean('for_pickup')->default(true)->comment('riders may be picked up here');
            $table->boolean('for_dropoff')->default(true)->comment('riders may be dropped off here');
            $table->boolean('status')->default(Status::Active->value);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['zone_id', 'status']);
        });

        Schema::create('ride_pickup_point_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ride_pickup_point_id')->constrained('ride_pickup_points')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('name', 120);
            $table->string('instructions', 255)->nullable();
            $table->unique(['ride_pickup_point_id', 'locale'], 'ride_pickup_point_translations_unique');
        });

        foreach (['ride_quotes', 'rides'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->json('pickup_point')->nullable()->comment('R-16: the approved pickup point chosen');
                $table->json('dropoff_point')->nullable()->comment('R-18: the approved drop-off point chosen');
            });
        }
    }

    public function down(): void
    {
        foreach (['rides', 'ride_quotes'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn(['pickup_point', 'dropoff_point']);
            });
        }
        Schema::dropIfExists('ride_pickup_point_translations');
        Schema::dropIfExists('ride_pickup_points');
    }
};
