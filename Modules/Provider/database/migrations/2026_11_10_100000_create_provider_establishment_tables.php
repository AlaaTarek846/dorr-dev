<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A-01 (docs/moving_tasks.md): the base every provider service stands on (moving first, then restaurants, home
 * services…). A person (a `providers` account) owns an establishment — an individual or a company — with branches;
 * people join it with a membership that carries their permissions and branches. Vehicles and documents belong to
 * the establishment and are reviewed by the admin before any offer can be sent, like the captains.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_establishments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('owner_id')->constrained('providers')->restrictOnDelete()->comment('the person who registered it');
            $table->string('type', 12)->comment('individual / company');
            $table->string('name', 150);
            $table->foreignId('country_id')->constrained('countries');
            $table->string('commercial_register_no', 64)->nullable();
            $table->string('tax_number', 64)->nullable();
            $table->string('status', 20)->default('draft')->comment('draft / under_review / approved / rejected / suspended');
            $table->string('status_reason', 500)->nullable()->comment('سبب الرفض أو الإيقاف، بيظهر لمقدم الخدمة');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by_admin_id')->nullable()->constrained('admins', 'id', 'prov_est_admin_fk')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'submitted_at']);
        });

        Schema::create('provider_establishment_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('establishment_id')->constrained('provider_establishments')->cascadeOnDelete();
            $table->foreignId('service_category_id')->constrained('service_categories')->cascadeOnDelete();
            $table->boolean('status')->default(true)->comment('the establishment switched it on');
            $table->timestamps();
            $table->unique(['establishment_id', 'service_category_id'], 'prov_est_service_unique');
        });

        Schema::create('provider_branches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('establishment_id')->constrained('provider_establishments')->cascadeOnDelete();
            $table->string('name', 150);
            $table->boolean('is_main')->default(false)->comment('made with the establishment; an individual sees only this one');
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->decimal('radius_km', 6, 2)->nullable()->comment('the area it serves around its point; requests whose pickup is inside reach it');
            $table->string('address', 255)->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['establishment_id', 'status']);
        });

        Schema::create('provider_memberships', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('establishment_id')->constrained('provider_establishments')->cascadeOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained('providers')->nullOnDelete()->comment('null while the invitation waits for a person');
            $table->string('phone', 32)->nullable()->comment('the number the invitation went to');
            $table->boolean('is_owner')->default(false);
            $table->boolean('is_exclusive')->default(false)->comment('shown to the person before accepting; their own establishment can not send offers while active');
            $table->boolean('all_branches')->default(true);
            $table->unsignedBigInteger('offer_limit_minor')->nullable()->comment('offers above this wait for someone with offers.approve; null = no limit');
            $table->string('status', 12)->default('invited')->comment('invited / active / declined / left / removed');
            $table->foreignId('invited_by_id')->nullable()->constrained('providers', 'id', 'prov_member_inviter_fk')->nullOnDelete();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->index(['provider_id', 'status']);
            $table->index(['establishment_id', 'status']);
            $table->index(['phone', 'status']);
        });

        Schema::create('provider_membership_branches', function (Blueprint $table) {
            $table->foreignId('membership_id')->constrained('provider_memberships')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('provider_branches')->cascadeOnDelete();
            $table->primary(['membership_id', 'branch_id']);
        });

        Schema::create('provider_membership_permissions', function (Blueprint $table) {
            $table->foreignId('membership_id')->constrained('provider_memberships')->cascadeOnDelete();
            $table->string('permission', 64)->comment('a key from Modules/Provider/config/config.php → permissions');
            $table->primary(['membership_id', 'permission']);
        });

        Schema::create('provider_membership_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('establishment_id')->constrained('provider_establishments')->cascadeOnDelete();
            $table->foreignId('membership_id')->nullable()->constrained('provider_memberships')->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('providers', 'id', 'prov_member_event_actor_fk')->nullOnDelete();
            $table->string('action', 32)->comment('invited / joined / declined / permissions_changed / branches_changed / removed / left');
            $table->json('changes')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['establishment_id', 'id']);
        });

        Schema::create('provider_vehicle_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 48)->unique();
            $table->boolean('status')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('provider_vehicle_type_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_vehicle_type_id')->constrained('provider_vehicle_types', 'id', 'prov_vtype_trans_fk')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->unique(['provider_vehicle_type_id', 'locale'], 'prov_vtype_locale_unique');
        });

        Schema::create('provider_vehicle_type_countries', function (Blueprint $table) {
            $table->foreignId('vehicle_type_id')->constrained('provider_vehicle_types')->cascadeOnDelete();
            $table->foreignId('country_id')->constrained('countries')->cascadeOnDelete();
            $table->primary(['vehicle_type_id', 'country_id']);
        });

        Schema::create('provider_vehicle_type_services', function (Blueprint $table) {
            $table->foreignId('vehicle_type_id')->constrained('provider_vehicle_types')->cascadeOnDelete();
            $table->foreignId('service_category_id')->constrained('service_categories')->cascadeOnDelete();
            $table->primary(['vehicle_type_id', 'service_category_id'], 'prov_vtype_service_primary');
        });

        Schema::create('provider_document_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 48)->unique();
            $table->string('subject', 16)->comment('establishment / vehicle / driver');
            $table->string('kind', 12)->default('document')->comment('document / photo — a photo is a picture of the vehicle itself (with its plate, an angle…)');
            $table->boolean('has_number')->default(false);
            $table->boolean('has_expiry')->default(false);
            $table->boolean('needs_back_side')->default(false);
            $table->unsignedSmallInteger('expiry_alert_days')->nullable()->comment('warn this many days before it ends');
            $table->boolean('status')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('provider_document_type_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_document_type_id')->constrained('provider_document_types', 'id', 'prov_dtype_trans_fk')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('name', 191);
            $table->string('instructions', 1000)->nullable()->comment('how to photograph it');
            $table->unique(['provider_document_type_id', 'locale'], 'prov_dtype_locale_unique');
        });

        Schema::create('provider_document_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_type_id')->constrained('provider_document_types', 'id', 'prov_dreq_type_fk')->cascadeOnDelete();
            $table->foreignId('country_id')->constrained('countries')->cascadeOnDelete();
            $table->foreignId('service_category_id')->nullable()->constrained('service_categories')->cascadeOnDelete()->comment('null = every service');
            $table->foreignId('vehicle_type_id')->nullable()->constrained('provider_vehicle_types')->cascadeOnDelete()->comment('null = every vehicle type');
            $table->string('establishment_type', 12)->nullable()->comment('null = both, individual / company');
            $table->boolean('is_required')->default(true);
            $table->timestamps();
            $table->index(['country_id', 'is_required']);
        });

        Schema::create('provider_vehicles', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('establishment_id')->constrained('provider_establishments')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('provider_branches')->nullOnDelete();
            $table->foreignId('vehicle_type_id')->constrained('provider_vehicle_types');
            $table->foreignId('country_id')->constrained('countries');
            $table->string('plate_letters', 32)->nullable();
            $table->string('plate_number', 20);
            $table->string('make', 60)->nullable();
            $table->string('model', 60)->nullable();
            $table->string('color', 40)->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('status', 12)->default('pending')->comment('pending / approved / rejected / inactive');
            $table->string('status_reason', 500)->nullable();
            $table->foreignId('reviewed_by_admin_id')->nullable()->constrained('admins', 'id', 'prov_vehicle_admin_fk')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['establishment_id', 'status']);
            $table->index(['country_id', 'plate_number']);
        });

        Schema::create('provider_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('establishment_id')->constrained('provider_establishments')->cascadeOnDelete();
            $table->string('subject_type', 16)->comment('establishment / vehicle / membership');
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('document_type_id')->constrained('provider_document_types', 'id', 'prov_doc_type_fk')->restrictOnDelete();
            $table->string('number', 64)->nullable();
            $table->date('expires_on')->nullable();
            $table->string('status', 12)->default('pending')->comment('pending / approved / rejected / expired / replaced');
            $table->string('rejection_reason', 500)->nullable();
            $table->foreignId('reviewed_by_admin_id')->nullable()->constrained('admins', 'id', 'prov_doc_admin_fk')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('expiry_alerted_at')->nullable();
            $table->timestamps();
            $table->index(['subject_type', 'subject_id', 'document_type_id', 'status'], 'prov_doc_lookup');
            $table->index(['status', 'expires_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_documents');
        Schema::dropIfExists('provider_vehicles');
        Schema::dropIfExists('provider_document_requirements');
        Schema::dropIfExists('provider_document_type_translations');
        Schema::dropIfExists('provider_document_types');
        Schema::dropIfExists('provider_vehicle_type_services');
        Schema::dropIfExists('provider_vehicle_type_countries');
        Schema::dropIfExists('provider_vehicle_type_translations');
        Schema::dropIfExists('provider_vehicle_types');
        Schema::dropIfExists('provider_membership_events');
        Schema::dropIfExists('provider_membership_permissions');
        Schema::dropIfExists('provider_membership_branches');
        Schema::dropIfExists('provider_memberships');
        Schema::dropIfExists('provider_branches');
        Schema::dropIfExists('provider_establishment_services');
        Schema::dropIfExists('provider_establishments');
    }
};
