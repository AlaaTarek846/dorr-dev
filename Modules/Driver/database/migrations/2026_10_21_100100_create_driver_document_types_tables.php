<?php

use App\Enums\Status;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The documents a driver or a vehicle may need, and where each is required (docs/rides-structure.md §1.2–1.3). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_document_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 48)->unique()->comment('مفتاح ثابت في الكود');
            $table->string('applies_to', 8)->comment('driver / vehicle');
            $table->boolean('has_number')->default(false)->comment('فيه رقم');
            $table->boolean('has_expiry')->default(false)->comment('فيه تاريخ انتهاء');
            $table->boolean('needs_back_side')->default(false)->comment('محتاج صورة الضهر');
            $table->unsignedSmallInteger('expiry_alert_days')->default(30)->comment('التنبيه قبل الانتهاء بكام يوم');
            $table->boolean('status')->default(Status::Active->value)->comment('الحالة');
            $table->unsignedInteger('sort_order')->default(0)->comment('الترتيب');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('driver_document_type_translations', function (Blueprint $table) {
            $table->id();
            // Short constraint name: MySQL allows 64 characters (the default would be 65).
            $table->foreignId('driver_document_type_id')->constrained('driver_document_types', 'id', 'driver_doc_type_tr_type_id_foreign')->cascadeOnDelete();
            $table->string('locale', 10)->comment('اللغة');
            $table->string('name')->comment('اسم المستند');
            $table->text('instructions')->nullable()->comment('إزاي السواق يصوّر المستند');
            $table->unique(['driver_document_type_id', 'locale'], 'driver_doc_type_locale_unique');
        });

        Schema::create('driver_document_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_type_id')->constrained('driver_document_types')->cascadeOnDelete();
            $table->foreignId('country_id')->constrained('countries')->cascadeOnDelete();
            $table->foreignId('service_category_id')->nullable()->constrained('service_categories')->cascadeOnDelete()->comment('null = مطلوب لأي خدمة في الدولة');
            $table->boolean('is_required')->default(true);
            $table->timestamps();
            $table->unique(['document_type_id', 'country_id', 'service_category_id'], 'driver_doc_requirement_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_document_requirements');
        Schema::dropIfExists('driver_document_type_translations');
        Schema::dropIfExists('driver_document_types');
    }
};
