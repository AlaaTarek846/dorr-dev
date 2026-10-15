<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Safety Center (R-219..230, D-140..160):
 *  - `ride_trusted_contacts`: people the rider / the captain trusts — each gets the ride's link by
 *    itself on every ride, at night only, or only when shared by hand, and "arrived safely".
 *  - `ride_safety_preferences`: the ride code (PIN) always / at night / as the city sets it, a
 *    check-in some minutes after arriving, the seatbelt reminder.
 *  - `ride_safety_checks`: "Are you OK?" — after a long stop or a detour during the ride, or the
 *    check-in after it; unanswered, it goes to the safety team and the trusted contacts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ride_trusted_contacts', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type', 8)->comment('user / driver');
            $table->unsignedBigInteger('owner_id');
            $table->string('name', 80);
            $table->string('phone', 20);
            $table->string('share_mode', 8)->default('manual')->comment('all / night / manual');
            $table->boolean('notify_arrival')->default(true);
            $table->timestamps();
            $table->index(['owner_type', 'owner_id']);
        });

        Schema::create('ride_safety_preferences', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type', 8);
            $table->unsignedBigInteger('owner_id');
            $table->string('pin_mode', 8)->default('policy')->comment('policy / always / night');
            $table->unsignedSmallInteger('checkin_after_min')->nullable()->comment('null = off');
            $table->boolean('seatbelt_reminder')->default(true);
            $table->timestamps();
            $table->unique(['owner_type', 'owner_id']);
        });

        Schema::create('ride_safety_checks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('ride_id')->constrained('rides')->cascadeOnDelete();
            $table->string('kind', 12)->comment('ride_check / checkin');
            $table->string('reason', 16)->comment('long_stop / deviation / scheduled');
            $table->string('target_type', 8)->comment('user / driver');
            $table->unsignedBigInteger('target_id');
            $table->timestamp('due_at')->comment('متى يُسأل');
            $table->timestamp('asked_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->string('answer', 8)->nullable()->comment('ok / help');
            $table->timestamp('escalated_at')->nullable();
            $table->json('meta')->nullable()->comment('المكان والمدة أو المسافة عن المسار');
            $table->timestamps();
            $table->index(['ride_id', 'kind']);
            $table->index(['due_at', 'asked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ride_safety_checks');
        Schema::dropIfExists('ride_safety_preferences');
        Schema::dropIfExists('ride_trusted_contacts');
    }
};
