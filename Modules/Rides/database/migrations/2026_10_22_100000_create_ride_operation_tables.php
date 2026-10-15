<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ride itself and everything that happens on it (docs/rides-structure.md §3–§4): quotes, the
 * ride, its holds, stops, events (append-only), the active locks, offers, price lines, adjustments,
 * the compressed track, the daily number counter, and the driver's settlement items.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ride_quotes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete()->comment('الراكب');
            $table->foreignId('country_id')->constrained('countries')->cascadeOnDelete();
            $table->unsignedBigInteger('city_id')->nullable();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->foreignId('category_id')->constrained('ride_categories')->cascadeOnDelete();
            $table->string('pricing_mode', 8)->comment('upfront / metered');
            $table->decimal('origin_lat', 10, 7);
            $table->decimal('origin_lng', 10, 7);
            $table->string('origin_address', 255)->nullable();
            $table->decimal('destination_lat', 10, 7)->nullable()->comment('null = من غير وجهة');
            $table->decimal('destination_lng', 10, 7)->nullable();
            $table->string('destination_address', 255)->nullable();
            $table->json('stops')->nullable();
            $table->unsignedInteger('distance_m')->nullable();
            $table->unsignedInteger('duration_s')->nullable();
            $table->boolean('route_estimated')->default(false)->comment('الحساب المجاني بدل Google');
            $table->mediumText('polyline')->nullable();
            $table->json('lines')->comment('أسطر السعر');
            $table->bigInteger('total_minor')->nullable()->comment('للمثبت');
            $table->bigInteger('estimate_min_minor')->nullable();
            $table->bigInteger('estimate_max_minor')->nullable();
            $table->bigInteger('hold_minor')->comment('اللي هيتحجز من المحفظة');
            $table->bigInteger('driver_net_minor')->nullable();
            $table->unsignedSmallInteger('version')->default(1);
            $table->char('fingerprint', 64);
            $table->json('policy_snapshot');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['user_id', 'created_at']);
            $table->index('expires_at');
        });

        Schema::create('rides', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('number', 24)->unique()->comment('DR-YYMMDD-NNNN — وهو tripId في وصل');
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete()->comment('الراكب');
            $table->json('beneficiary')->nullable();
            $table->foreignId('category_id')->constrained('ride_categories')->restrictOnDelete();
            $table->foreignId('country_id')->constrained('countries')->restrictOnDelete();
            $table->unsignedBigInteger('city_id')->nullable();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->string('status', 20);
            $table->string('booking_mode', 12)->default('instant');
            $table->string('pricing_mode', 8);
            $table->boolean('open_destination')->default(false);
            $table->foreignId('quote_id')->nullable()->constrained('ride_quotes')->nullOnDelete();
            $table->json('confirmed_snapshot')->comment('ثابتة بعد التأكيد');
            $table->bigInteger('rider_offer_minor')->nullable();
            $table->bigInteger('agreed_price_minor')->nullable();
            $table->bigInteger('final_total_minor')->nullable();
            $table->bigInteger('commission_minor')->nullable();
            $table->bigInteger('tax_minor')->nullable();
            $table->bigInteger('driver_net_minor')->nullable();
            $table->bigInteger('previous_balance_minor')->default(0)->comment('دين قديم بيتحصّل مع الرحلة');
            $table->string('payment_method', 8);
            $table->string('payment_status', 16);
            $table->foreignId('topup_payment_transaction_id')->nullable()->constrained('payment_transactions')->nullOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained('drivers')->restrictOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->restrictOnDelete();
            $table->json('vehicle_snapshot')->nullable();
            $table->string('pin', 8)->nullable()->comment('PIN البدء — بيظهر للراكب بس');
            $table->unsignedTinyInteger('pin_attempts')->default(0);
            $table->decimal('origin_lat', 10, 7);
            $table->decimal('origin_lng', 10, 7);
            $table->string('origin_address', 255)->nullable();
            $table->decimal('destination_lat', 10, 7)->nullable();
            $table->decimal('destination_lng', 10, 7)->nullable();
            $table->string('destination_address', 255)->nullable();
            $table->string('origin_city_ar', 80)->nullable();
            $table->string('destination_city_ar', 80)->nullable();
            $table->mediumText('planned_polyline')->nullable();
            $table->unsignedInteger('planned_distance_m')->nullable();
            $table->unsignedInteger('planned_duration_s')->nullable();
            $table->unsignedInteger('metered_distance_m')->nullable();
            $table->unsignedInteger('metered_duration_s')->nullable();
            $table->string('distance_source', 16)->nullable();
            $table->unsignedInteger('gps_gap_s')->default(0);
            $table->unsignedInteger('pickup_waiting_s')->default(0);
            $table->unsignedInteger('stops_waiting_s')->default(0);
            $table->unsignedInteger('search_radius_m')->nullable();
            $table->unsignedSmallInteger('dispatch_round')->default(0);
            $table->timestamp('next_dispatch_at')->nullable();
            $table->timestamp('rider_asked_at')->nullable()->comment('اتسأل يكمل البحث ولا لأ');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->string('cancel_actor', 8)->nullable();
            $table->string('cancel_stage', 16)->nullable();
            $table->foreignId('cancel_reason_id')->nullable()->constrained('ride_cancel_reasons')->nullOnDelete();
            $table->string('cancel_note', 255)->nullable();
            $table->unsignedBigInteger('chat_conversation_id')->nullable();
            $table->string('wasl_status', 12)->nullable();
            $table->unsignedTinyInteger('wasl_attempts')->default(0);
            $table->timestamp('wasl_last_attempt_at')->nullable();
            $table->timestamp('wasl_sent_at')->nullable();
            $table->string('wasl_result_code', 64)->nullable();
            $table->string('idempotency_key', 80)->nullable();
            $table->char('request_hash', 64)->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['user_id', 'status']);
            $table->index(['driver_id', 'status']);
            $table->index(['status', 'next_dispatch_at']);
            $table->index(['status', 'scheduled_at']);
            $table->index(['country_id', 'city_id', 'status']);
            $table->index(['wasl_status', 'wasl_last_attempt_at']);
            $table->index('created_at');
        });

        Schema::create('ride_counters', function (Blueprint $table) {
            $table->string('key', 40)->primary();
            $table->unsignedInteger('value')->default(0);
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('ride_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ride_id')->constrained('rides')->cascadeOnDelete();
            $table->foreignId('wallet_hold_id')->unique()->constrained('wallet_holds')->restrictOnDelete();
            $table->string('reason', 16)->comment('initial / bid_increase / change_request / open_trip');
            $table->bigInteger('amount_minor');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('ride_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ride_id')->constrained('rides')->cascadeOnDelete();
            $table->unsignedTinyInteger('seq');
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->string('address', 255)->nullable();
            $table->string('status', 8)->default('pending')->comment('pending / arrived / done / skipped');
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('departed_at')->nullable();
            $table->unsignedInteger('wait_s')->default(0);
            $table->unsignedBigInteger('added_by_change_request_id')->nullable();
            $table->unique(['ride_id', 'seq']);
        });

        // Append-only: the evidence of who did what, where and when (server time).
        Schema::create('ride_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ride_id')->constrained('rides')->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->string('actor_type', 8)->comment('user / driver / admin / system');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->unsignedSmallInteger('accuracy_m')->nullable();
            $table->json('payload')->nullable();
            $table->string('idempotency_key', 80)->nullable()->unique();
            $table->char('request_hash', 64)->nullable();
            $table->timestamp('created_at', 3)->nullable();
            $table->index(['ride_id', 'id']);
            $table->index(['type', 'created_at']);
        });

        // One row per "busy" thing: a driver's active ride, a rider's active ride. The primary key is
        // what makes a second accept impossible, on MySQL and SQLite alike.
        Schema::create('ride_active_locks', function (Blueprint $table) {
            $table->string('lock_key', 40)->primary();
            $table->foreignId('ride_id')->constrained('rides')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('ride_offers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('ride_id')->constrained('rides')->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained('drivers')->cascadeOnDelete();
            $table->unsignedSmallInteger('round');
            $table->unsignedInteger('distance_m');
            $table->unsignedInteger('eta_s');
            $table->bigInteger('driver_net_minor')->nullable();
            $table->integer('score')->default(0);
            $table->string('status', 10)->comment('sent / accepted / declined / expired / withdrawn');
            $table->boolean('auto_accepted')->default(false);
            $table->timestamp('sent_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->string('decline_reason', 32)->nullable();
            $table->unique(['ride_id', 'driver_id', 'round']);
            $table->index(['driver_id', 'status']);
            $table->index(['status', 'expires_at']);
        });

        // Append-only: `confirmed` lines at confirmation, `final` ones once at the end.
        Schema::create('ride_price_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ride_id')->constrained('rides')->cascadeOnDelete();
            $table->string('phase', 12);
            $table->string('code', 32);
            $table->bigInteger('amount_minor')->comment('بإشارة');
            $table->string('visible_to', 8)->comment('rider / driver / both / internal');
            $table->json('meta')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->index(['ride_id', 'phase']);
        });

        Schema::create('ride_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ride_id')->constrained('rides')->cascadeOnDelete();
            $table->string('type', 24);
            $table->bigInteger('amount_minor')->comment('بإشارة');
            $table->string('affects', 8)->comment('rider / driver / both');
            $table->string('reason_key', 64)->nullable();
            $table->string('actor_type', 8)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('reference_type', 32)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index('ride_id');
        });

        Schema::create('ride_tracks', function (Blueprint $table) {
            $table->foreignId('ride_id')->primary()->constrained('rides')->cascadeOnDelete();
            $table->mediumText('polyline')->nullable();
            $table->unsignedInteger('points_count')->default(0);
            $table->unsignedInteger('track_distance_m')->default(0);
            $table->unsignedInteger('device_distance_m')->nullable();
            $table->json('gaps')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('ride_settlement_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('ride_id')->constrained('rides')->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained('drivers')->restrictOnDelete();
            $table->string('type', 24);
            $table->bigInteger('amount_minor')->comment('بإشارة: + للسواق، − عليه');
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->string('status', 10)->comment('pending / posted / held / reversed');
            $table->timestamp('release_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('wallet_transaction_id')->nullable()->constrained('wallet_transactions')->nullOnDelete();
            $table->foreignId('financial_entry_id')->nullable()->constrained('financial_entries')->nullOnDelete();
            $table->string('reason_key', 64)->nullable();
            $table->timestamps();
            $table->index(['driver_id', 'status']);
            $table->index(['status', 'release_at']);
            $table->index('ride_id');
        });
    }

    public function down(): void
    {
        foreach (['ride_settlement_items', 'ride_tracks', 'ride_adjustments', 'ride_price_lines', 'ride_offers', 'ride_active_locks', 'ride_events', 'ride_stops', 'ride_holds', 'ride_counters', 'rides', 'ride_quotes'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
