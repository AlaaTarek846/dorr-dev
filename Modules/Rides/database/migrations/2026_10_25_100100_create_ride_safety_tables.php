<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Safety (R-86, R-110, R-111, R-113, D-141, D-142, D-156):
 *  - `ride_shares`: a link the rider (or the captain) sends to family — a public page with the
 *    ride's live state, the car and where it is; it stops showing the place when the ride ends and
 *    dies `safety.share_ttl_after_end_s` later, or when revoked.
 *  - `ride_safety_incidents`: an SOS from either side — where and when, then who handled it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ride_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ride_id')->constrained('rides')->cascadeOnDelete();
            $table->char('token', 40)->unique();
            $table->string('sharer_type', 8)->comment('user / driver');
            $table->unsignedBigInteger('sharer_id');
            $table->unsignedInteger('views')->default(0);
            $table->timestamp('last_viewed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['ride_id', 'sharer_type', 'sharer_id']);
        });

        Schema::create('ride_safety_incidents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('ride_id')->constrained('rides')->cascadeOnDelete();
            $table->string('type', 16)->default('sos');
            $table->string('reporter_type', 8)->comment('user / driver');
            $table->unsignedBigInteger('reporter_id');
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->unsignedSmallInteger('accuracy_m')->nullable();
            $table->string('ride_status', 20)->comment('حالة الرحلة لحظة البلاغ');
            $table->string('note', 500)->nullable();
            $table->string('status', 16)->default('open')->comment('open / acknowledged / resolved');
            $table->foreignId('handled_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution_note', 1000)->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index('ride_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ride_safety_incidents');
        Schema::dropIfExists('ride_shares');
    }
};
