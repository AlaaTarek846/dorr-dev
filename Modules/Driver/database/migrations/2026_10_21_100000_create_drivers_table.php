<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Driver\Enums\DriverStatus;

/**
 * The driver's own account (docs/rides-structure.md §1.1) — not a Provider. The same phone
 * can also be a rider in `users`: two accounts, two apps.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 150)->nullable()->comment('الاسم اللي بيظهر');
            $table->string('name_ar', 150)->nullable()->comment('الاسم بالعربي زي ما وصل رجّعه');
            $table->string('name_en', 150)->nullable()->comment('الاسم بالإنجليزي زي ما وصل رجّعه');
            $table->string('phone', 20)->unique()->comment('رقم الموبايل بصيغة E.164');
            $table->timestamp('phone_verified_at')->nullable()->comment('وقت تأكيد الرقم');
            $table->string('email')->nullable()->unique();
            $table->string('gender', 8)->nullable()->comment('male / female — لفئة لكِ سيدتي');
            $table->date('date_of_birth')->nullable()->comment('ميلادي، والهجري بيتحسب وقت الإرسال لوصل');
            $table->string('national_id', 20)->nullable()->comment('رقم الهوية أو الإقامة');
            $table->string('id_type', 16)->nullable()->comment('national_id / iqama / passport');
            $table->foreignId('nationality_country_id')->nullable()->constrained('countries')->nullOnDelete()->comment('الجنسية');
            $table->foreignId('country_id')->constrained('countries')->restrictOnDelete()->comment('دولة الشغل');
            $table->unsignedBigInteger('city_id')->nullable()->comment('مدينة الشغل (discover_cities.id)');
            $table->string('locale', 10)->default('ar')->comment('لغة الإشعارات');
            $table->string('status', 24)->default(DriverStatus::PendingDocuments->value)->comment('حالة الحساب');
            $table->string('status_reason')->nullable()->comment('سبب الإيقاف أو التقييد بصياغة آمنة');
            $table->string('registered_by', 8)->default('app')->comment('app / admin');
            $table->foreignId('registered_by_admin_id')->nullable()->constrained('admins')->nullOnDelete()->comment('الأدمن اللي سجّله');
            $table->timestamp('activated_at')->nullable()->comment('أول تفعيل');
            $table->timestamp('onboarding_ends_at')->nullable()->comment('نهاية فترة التأهيل');
            $table->timestamp('last_active_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['id_type', 'national_id']);
            $table->index(['country_id', 'status']);
            $table->index(['city_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drivers');
    }
};
