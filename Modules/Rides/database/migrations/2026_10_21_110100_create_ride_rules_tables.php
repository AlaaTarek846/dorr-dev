<?php

use App\Enums\Status;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Everything the admin turns on, prices and limits: feature switches, zones, policies (prices
 * inside), tax rules and the regulator's exact city names (docs/rides-structure.md §2.3–2.6, §7.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Same shape as chat_feature_rules; unlike the chat, no rule means OFF (§5 of the plan).
        Schema::create('ride_feature_rules', function (Blueprint $table) {
            $table->id();
            $table->string('feature', 48)->comment('rides / category.{slug} / bidding / multi_stop …');
            $table->foreignId('country_id')->constrained('countries')->cascadeOnDelete();
            $table->unsignedBigInteger('city_id')->nullable()->comment('null = الدولة كلها (discover_cities.id)');
            $table->boolean('enabled')->default(false);
            $table->string('note', 255)->nullable()->comment('سبب للإدارة: رخصة، تجربة…');
            $table->timestamps();
            $table->unique(['feature', 'country_id', 'city_id']);
            $table->index(['feature', 'city_id']);
        });

        Schema::create('ride_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->constrained('countries')->cascadeOnDelete();
            $table->unsignedBigInteger('city_id')->nullable();
            $table->string('type', 16)->comment('service_area / blocked / airport / surcharge / pickup_only');
            $table->json('polygon')->comment('[[lat, lng], …]');
            $table->decimal('min_lat', 10, 7);
            $table->decimal('max_lat', 10, 7);
            $table->decimal('min_lng', 10, 7);
            $table->decimal('max_lng', 10, 7);
            $table->json('config')->nullable()->comment('حسب النوع: رسوم المنطقة أو المطار');
            $table->unsignedSmallInteger('priority')->default(0);
            $table->boolean('status')->default(Status::Active->value);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['country_id', 'type', 'status']);
            $table->index(['min_lat', 'max_lat']);
        });

        Schema::create('ride_zone_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ride_zone_id')->constrained('ride_zones')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('name', 120);
            $table->unique(['ride_zone_id', 'locale']);
        });

        Schema::create('ride_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->constrained('countries')->cascadeOnDelete();
            $table->unsignedBigInteger('city_id')->nullable();
            $table->foreignId('zone_id')->nullable()->constrained('ride_zones')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('ride_categories')->cascadeOnDelete();
            $table->json('data')->comment('المفاتيح اللي النطاق ده بيغيرها بس');
            $table->boolean('status')->default(Status::Active->value);
            $table->foreignId('updated_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
            $table->unique(['country_id', 'city_id', 'zone_id', 'category_id'], 'ride_policies_scope_unique');
        });

        Schema::create('ride_tax_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->constrained('countries')->cascadeOnDelete();
            $table->string('code', 32)->comment('vat');
            $table->string('base', 16)->comment('commission / fare / fare_and_fees');
            $table->unsignedInteger('rate_bp')->comment('1500 = 15%');
            $table->string('borne_by', 8)->comment('driver / rider');
            $table->timestamp('effective_from');
            $table->timestamp('effective_to')->nullable();
            $table->boolean('status')->default(Status::Active->value);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['country_id', 'status', 'effective_from']);
        });

        Schema::create('ride_tax_rule_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ride_tax_rule_id')->constrained('ride_tax_rules')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('label', 160);
            $table->unique(['ride_tax_rule_id', 'locale']);
        });

        // Wasl rejects a trip if the city name is off by one letter: the exact official names only,
        // chosen by the admin — never a translation, never Google (owner's decision, §7.2).
        Schema::create('ride_regulator_cities', function (Blueprint $table) {
            $table->id();
            $table->string('regulator', 16)->comment('wasl');
            $table->foreignId('country_id')->constrained('countries')->cascadeOnDelete();
            $table->unsignedBigInteger('city_id')->nullable()->comment('مدينتنا — null = لسه متربطش');
            $table->string('official_name', 80)->comment('الاسم الرسمي بالحرف');
            $table->timestamp('accepted_at')->nullable()->comment('أول رحلة اتقبلت بالاسم ده');
            $table->timestamp('last_rejected_at')->nullable();
            $table->boolean('status')->default(Status::Active->value);
            $table->timestamps();
            $table->unique(['regulator', 'official_name']);
            $table->unique(['regulator', 'city_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ride_regulator_cities');
        Schema::dropIfExists('ride_tax_rule_translations');
        Schema::dropIfExists('ride_tax_rules');
        Schema::dropIfExists('ride_policies');
        Schema::dropIfExists('ride_zone_translations');
        Schema::dropIfExists('ride_zones');
        Schema::dropIfExists('ride_feature_rules');
    }
};
