<?php

use App\Enums\Status;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ride categories, their extra documents, cancel reasons, rating tags and the drivers'
 * points rules (docs/rides-structure.md §2.1, §2.2, §2.8, §2.9).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ride_categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 32)->unique()->comment('مفتاح ثابت: economy / ladies / vip …');
            $table->string('pricing_strategy', 16)->comment('distance / trip_flat / bid / seat / time');
            $table->unsignedTinyInteger('capacity_min')->default(1)->comment('أقل عدد ركاب');
            $table->unsignedTinyInteger('capacity_max')->default(4)->comment('أكبر عدد ركاب');
            $table->json('eligibility')->nullable()->comment('شروط السواق والمركبة');
            $table->json('flags')->nullable()->comment('cash_allowed / surge_allowed / open_destination_allowed …');
            $table->char('color', 7)->nullable()->comment('لون الفئة في التطبيق');
            $table->boolean('status')->default(Status::Active->value);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('ride_category_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ride_category_id')->constrained('ride_categories')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('name', 80)->comment('اسم الفئة');
            $table->string('tagline', 160)->nullable()->comment('سطر تحت الاسم');
            $table->text('description')->nullable();
            $table->unique(['ride_category_id', 'locale']);
        });

        Schema::create('ride_category_document_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('ride_categories')->cascadeOnDelete();
            $table->foreignId('document_type_id')->constrained('driver_document_types')->cascadeOnDelete();
            $table->unique(['category_id', 'document_type_id'], 'ride_category_document_unique');
        });

        Schema::create('ride_cancel_reasons', function (Blueprint $table) {
            $table->id();
            $table->string('actor', 8)->comment('rider / driver / both');
            $table->json('stages')->nullable()->comment('المراحل اللي السبب بيظهر فيها');
            $table->boolean('is_safety')->default(false)->comment('إلغاء أمني له مسار لوحده');
            $table->boolean('requires_note')->default(false);
            $table->boolean('status')->default(Status::Active->value);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('ride_cancel_reason_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ride_cancel_reason_id')->constrained('ride_cancel_reasons')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('label', 160);
            $table->unique(['ride_cancel_reason_id', 'locale'], 'ride_cancel_reason_locale_unique');
        });

        Schema::create('ride_rating_tags', function (Blueprint $table) {
            $table->id();
            $table->string('target', 8)->comment('driver / rider');
            $table->string('sentiment', 8)->comment('positive / negative');
            $table->boolean('status')->default(Status::Active->value);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('ride_rating_tag_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ride_rating_tag_id')->constrained('ride_rating_tags')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('label', 80);
            $table->unique(['ride_rating_tag_id', 'locale']);
        });

        Schema::create('driver_points_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->nullable()->constrained('countries')->cascadeOnDelete()->comment('null = كل الدول');
            $table->string('event', 32)->comment('trip_completed / on_time_pickup / five_stars / challenge / late_cancel');
            $table->integer('points')->comment('بإشارة');
            $table->json('conditions')->nullable();
            $table->boolean('status')->default(Status::Active->value);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['event', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_points_rules');
        Schema::dropIfExists('ride_rating_tag_translations');
        Schema::dropIfExists('ride_rating_tags');
        Schema::dropIfExists('ride_cancel_reason_translations');
        Schema::dropIfExists('ride_cancel_reasons');
        Schema::dropIfExists('ride_category_document_types');
        Schema::dropIfExists('ride_category_translations');
        Schema::dropIfExists('ride_categories');
    }
};
