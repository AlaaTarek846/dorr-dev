<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * «أنا معك» — With Me (docs/rides_tasks.md O-30..O-33, the owner's decisions of 2026-10-19): a captain
 * carries several riders going his way.
 *  - a pool: either the captain announced where he's going and how far off his road he'll go
 *    (`route`), or a rider's With Me ride started and others on the way may join it (`ride`);
 *  - each joining ride is split: the captain takes a share of the newcomer's price, the rest comes off
 *    everyone's fare — equally or by the distance shared (the admin's choice), never under a floor;
 *  - each rider chose who may share with them: anyone, men only, or women only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ride_pools', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('driver_id')->constrained('drivers')->cascadeOnDelete();
            $table->string('mode', 8)->comment('route = the captain set where he goes / ride = a rider\'s ride took others');
            $table->string('status', 8)->default('open')->comment('open / closed');
            $table->decimal('destination_lat', 10, 7);
            $table->decimal('destination_lng', 10, 7);
            $table->string('destination_address', 255)->nullable();
            $table->unsignedInteger('radius_m');
            $table->unsignedTinyInteger('seats');
            $table->dateTime('closed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'driver_id']);
        });

        Schema::table('rides', function (Blueprint $table) {
            $table->foreignId('pool_id')->nullable()->after('driver_id')->constrained('ride_pools')->nullOnDelete();
            $table->string('share_with', 8)->default('any')->after('pool_id')->comment('With Me: any / male / female');
            $table->unsignedBigInteger('pool_discount_minor')->default(0)->after('share_with')->comment('what sharing took off this fare');
        });

        Schema::create('ride_pool_splits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ride_pool_id')->constrained('ride_pools')->cascadeOnDelete();
            $table->foreignId('joining_ride_id')->constrained('rides')->cascadeOnDelete();
            $table->unsignedBigInteger('price_minor')->comment('the newcomer\'s own price');
            $table->unsignedBigInteger('driver_cut_minor');
            $table->string('method', 12)->comment('proportional / equal');
            $table->json('discounts')->comment('{ride_id: minor} — what each rider got off');
            $table->dateTime('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ride_pool_splits');
        Schema::table('rides', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pool_id');
            $table->dropColumn(['share_with', 'pool_discount_minor']);
        });
        Schema::dropIfExists('ride_pools');
    }
};
