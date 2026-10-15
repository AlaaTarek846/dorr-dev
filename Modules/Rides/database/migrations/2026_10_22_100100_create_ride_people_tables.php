<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The driver and the rider as rides sees them (docs/rides-structure.md §5–§8): profiles, category
 * eligibility, preferences, daily stats, points, blocks, favourites, the regulator's status and
 * log, and the rating tags picked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_ride_profiles', function (Blueprint $table) {
            $table->foreignId('driver_id')->primary()->constrained('drivers')->cascadeOnDelete();
            $table->string('tier', 16)->default('new');
            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->unsignedInteger('rating_count')->default(0);
            $table->unsignedInteger('trips_count')->default(0);
            $table->unsignedInteger('acceptance_bp')->default(10000);
            $table->unsignedInteger('cancel_bp')->default(0);
            $table->unsignedInteger('completion_bp')->default(10000);
            $table->integer('points_balance')->default(0);
            $table->timestamp('last_trip_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('driver_category_eligibility', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('drivers')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('ride_categories')->cascadeOnDelete();
            $table->string('status', 12)->comment('eligible / ineligible / suspended / pending');
            $table->string('reason_code', 48)->nullable();
            $table->boolean('admin_override')->default(false);
            $table->foreignId('decided_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
            $table->unique(['driver_id', 'category_id']);
        });

        Schema::create('driver_preferences', function (Blueprint $table) {
            $table->foreignId('driver_id')->primary()->constrained('drivers')->cascadeOnDelete();
            $table->json('categories')->nullable()->comment('null = كل اللي مؤهل ليها');
            $table->unsignedInteger('pickup_radius_m')->nullable();
            $table->json('destination')->nullable();
            $table->json('auto_accept')->nullable();
            $table->string('navigation_app', 16)->default('google_maps');
            $table->json('sounds')->nullable();
            $table->boolean('voice_readout')->default(false);
            $table->boolean('hide_earnings')->default(false);
            $table->bigInteger('daily_goal_minor')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('driver_daily_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('drivers')->cascadeOnDelete();
            $table->date('stat_date');
            $table->unsignedInteger('online_s')->default(0);
            $table->unsignedInteger('trips')->default(0);
            $table->unsignedInteger('offers_received')->default(0);
            $table->unsignedInteger('offers_accepted')->default(0);
            $table->unsignedInteger('offers_declined')->default(0);
            $table->unsignedInteger('cancels')->default(0);
            $table->bigInteger('gross_minor')->default(0);
            $table->bigInteger('net_minor')->default(0);
            $table->bigInteger('tips_minor')->default(0);
            $table->bigInteger('bonus_minor')->default(0);
            $table->bigInteger('commission_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->unsignedInteger('distance_with_rider_m')->default(0);
            $table->unsignedInteger('distance_empty_m')->default(0);
            $table->integer('points')->default(0);
            $table->timestamp('updated_at')->nullable();
            $table->unique(['driver_id', 'stat_date']);
        });

        Schema::create('driver_points_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('drivers')->cascadeOnDelete();
            $table->integer('points');
            $table->integer('balance_after');
            $table->string('event', 32);
            $table->foreignId('rule_id')->nullable()->constrained('driver_points_rules')->nullOnDelete();
            $table->foreignId('ride_id')->nullable()->constrained('rides')->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['driver_id', 'id']);
        });

        Schema::create('rider_ride_profiles', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->unsignedInteger('rating_count')->default(0);
            $table->unsignedInteger('trips_count')->default(0);
            $table->unsignedInteger('cancels_count')->default(0);
            $table->unsignedInteger('no_show_count')->default(0);
            $table->timestamp('last_trip_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('ride_blocks', function (Blueprint $table) {
            $table->id();
            $table->string('blocker_type', 8);
            $table->unsignedBigInteger('blocker_id');
            $table->string('blocked_type', 8);
            $table->unsignedBigInteger('blocked_id');
            $table->foreignId('ride_id')->nullable()->constrained('rides')->nullOnDelete();
            $table->string('reason', 255)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['blocker_type', 'blocker_id', 'blocked_type', 'blocked_id'], 'ride_blocks_pair_unique');
            $table->index(['blocked_type', 'blocked_id']);
        });

        Schema::create('favorite_drivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained('drivers')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->unique(['user_id', 'driver_id']);
        });

        Schema::create('driver_regulator_status', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('drivers')->cascadeOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->string('regulator', 16);
            $table->foreignId('country_id')->constrained('countries')->cascadeOnDelete();
            $table->string('driver_status', 12)->default('pending');
            $table->string('vehicle_status', 12)->default('pending');
            $table->date('driver_expires_at')->nullable();
            $table->date('vehicle_expires_at')->nullable();
            $table->string('criminal_record_status', 32)->nullable();
            $table->json('rejection_codes')->nullable();
            $table->date('blocked_until')->nullable();
            $table->unsignedSmallInteger('registration_attempts')->default(0);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('next_check_at')->nullable();
            $table->timestamps();
            $table->unique(['driver_id', 'regulator']);
        });

        Schema::create('wasl_logs', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 16)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('type', 24);
            $table->json('request')->nullable();
            $table->json('response')->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->boolean('success')->default(false);
            $table->string('result_code', 64)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['subject_type', 'subject_id']);
            $table->index(['type', 'created_at']);
        });

        Schema::create('ride_rating_tag_selections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rating_id')->constrained('ratings')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('ride_rating_tags')->cascadeOnDelete();
            $table->unique(['rating_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        foreach (['ride_rating_tag_selections', 'wasl_logs', 'driver_regulator_status', 'favorite_drivers', 'ride_blocks', 'rider_ride_profiles', 'driver_points_ledger', 'driver_daily_stats', 'driver_preferences', 'driver_category_eligibility', 'driver_ride_profiles'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
