<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B of docs/moving_tasks.md: a customer's move request, the branches it reached, the establishments' offers,
 * every step it went through, and the values each country set for moving.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('moving_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->nullable()->unique()->constrained('countries')->cascadeOnDelete()->comment('null = the default for every country');
            $table->json('values')->comment('keys from Modules/Moving/config/config.php → policy_keys');
            $table->unsignedInteger('version')->default(1)->comment('goes up on every save; a request keeps the one it was published under');
            $table->foreignId('updated_by_admin_id')->nullable()->constrained('admins', 'id', 'moving_policy_admin_fk')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('moving_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('country_id')->constrained('countries');
            $table->string('currency_code', 3);
            $table->foreignId('service_category_id')->constrained('service_categories');
            $table->string('status', 20)->default('draft')->comment('draft / open / expired / withdrawn / confirmed / assigned / … see MovingStatus');
            $table->string('load_type', 16)->comment('single_piece / home / commercial');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('pieces_count')->nullable();
            $table->string('dimensions', 255)->nullable();
            $table->foreignId('vehicle_type_id')->nullable()->constrained('provider_vehicle_types')->nullOnDelete();
            $table->unsignedSmallInteger('workers_count')->default(0)->comment('how many will carry it (owner: one number, REQ-098 change)');
            $table->boolean('packing')->default(false);
            $table->boolean('disassembly')->default(false);
            $table->boolean('crane')->default(false);
            $table->boolean('technician')->default(false);
            foreach (['pickup', 'dropoff'] as $end) {
                $table->decimal("{$end}_lat", 10, 7)->nullable();
                $table->decimal("{$end}_lng", 10, 7)->nullable();
                $table->string("{$end}_address", 255)->nullable()->comment('shown to the provider only after its offer is accepted (REQ-019)');
                $table->string("{$end}_area", 120)->nullable()->comment('the neighbourhood, all a provider sees before (REQ-097)');
                $table->smallInteger("{$end}_floor")->nullable();
                $table->boolean("{$end}_elevator")->default(false);
                $table->string("{$end}_entrance", 255)->nullable();
            }
            $table->dateTime('scheduled_at')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('policy_version')->nullable();
            $table->json('policy')->nullable()->comment('the country values in force when published (REQ-003)');
            $table->dateTime('offers_deadline_at')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->unsignedBigInteger('accepted_offer_id')->nullable();
            $table->foreignId('establishment_id')->nullable()->constrained('provider_establishments')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('provider_branches')->nullOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('provider_vehicles')->nullOnDelete();
            $table->foreignId('driver_membership_id')->nullable()->constrained('provider_memberships')->nullOnDelete();
            $table->dateTime('driver_accepted_at')->nullable();
            $table->unsignedBigInteger('price_minor')->nullable()->comment('the accepted offer, kept as agreed (REQ-026)');
            $table->unsignedBigInteger('tax_minor')->nullable();
            $table->unsignedBigInteger('total_minor')->nullable()->comment('what the customer pays and is held');
            $table->unsignedBigInteger('commission_minor')->nullable();
            $table->unsignedBigInteger('provider_net_minor')->nullable();
            $table->string('payment_status', 12)->nullable()->comment('held / settled / refunded');
            $table->string('cancelled_by', 16)->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
            $table->index(['status', 'scheduled_at']);
            $table->index(['establishment_id', 'status']);
        });

        Schema::create('moving_request_branches', function (Blueprint $table) {
            $table->foreignId('request_id')->constrained('moving_requests')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('provider_branches')->cascadeOnDelete();
            $table->foreignId('establishment_id')->constrained('provider_establishments')->cascadeOnDelete();
            $table->decimal('distance_km', 8, 2)->nullable();
            $table->primary(['request_id', 'branch_id']);
            $table->index(['branch_id', 'request_id']);
            $table->index(['establishment_id', 'request_id']);
        });

        Schema::create('moving_offers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('request_id')->constrained('moving_requests')->cascadeOnDelete();
            $table->foreignId('establishment_id')->constrained('provider_establishments')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('provider_branches')->nullOnDelete();
            $table->foreignId('vehicle_id')->constrained('provider_vehicles');
            $table->foreignId('created_by_membership_id')->nullable()->constrained('provider_memberships', 'id', 'moving_offer_creator_fk')->nullOnDelete();
            $table->foreignId('approved_by_membership_id')->nullable()->constrained('provider_memberships', 'id', 'moving_offer_approver_fk')->nullOnDelete();
            $table->unsignedBigInteger('price_minor');
            $table->string('currency_code', 3);
            $table->unsignedSmallInteger('workers_count')->default(0);
            $table->unsignedSmallInteger('trips_count')->default(1);
            $table->json('includes')->nullable()->comment('packing / disassembly / crane / technician / tax');
            $table->string('technician_note', 255)->nullable();
            $table->dateTime('arrival_at');
            $table->text('terms')->nullable();
            $table->text('after_service')->nullable()->comment('any promise after the move, its length and exceptions (REQ-065)');
            $table->dateTime('valid_until');
            $table->string('status', 20)->default('sent')->comment('awaiting_approval / sent / withdrawn / accepted / declined / expired');
            $table->timestamps();
            $table->index(['request_id', 'status']);
            $table->index(['establishment_id', 'status']);
        });

        Schema::create('moving_request_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('moving_requests')->cascadeOnDelete();
            $table->string('actor_type', 16)->comment('user / provider / admin / system');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action', 40);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->json('data')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['request_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('moving_request_events');
        Schema::dropIfExists('moving_offers');
        Schema::dropIfExists('moving_request_branches');
        Schema::dropIfExists('moving_requests');
        Schema::dropIfExists('moving_policies');
    }
};
