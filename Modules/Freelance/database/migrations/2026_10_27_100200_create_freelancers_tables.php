<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A freelancer («محترف») is their own account on the `freelance_api` guard, like a driver — not a
 * Provider and not a User. Someone can be a Dorr user and a freelancer: two accounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freelancers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 150)->nullable()->comment('الاسم اللي بيظهر');
            $table->string('phone', 32)->unique()->comment('رقم الموبايل بصيغة E.164 (بعد الحذف: deleted-{id})');
            $table->timestamp('phone_verified_at')->nullable()->comment('وقت تأكيد الرقم');
            $table->string('email')->nullable()->unique();
            $table->string('gender', 8)->nullable()->comment('male / female');
            $table->date('date_of_birth')->nullable();
            $table->foreignId('country_id')->constrained('countries')->restrictOnDelete()->comment('دولة الحساب');
            $table->string('locale', 10)->default('ar')->comment('لغة الإشعارات');
            $table->string('status', 24)->default('incomplete')->comment('incomplete / pending_verification / active / suspended / blocked');
            $table->string('status_reason')->nullable()->comment('سبب الإيقاف أو الحظر بصياغة آمنة');
            $table->timestamp('terms_accepted_at')->nullable()->comment('وقت الموافقة على الشروط');
            $table->string('terms_version', 20)->nullable()->comment('نسخة الشروط اللي وافق عليها');
            $table->timestamp('last_active_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['country_id', 'status']);
        });

        // One row per signed-in device (app, website, dashboard): the freelancer sees them and can end one.
        Schema::create('freelancer_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('freelancer_id')->constrained('freelancers')->cascadeOnDelete();
            $table->unsignedBigInteger('token_id')->nullable()->unique()->comment('personal_access_tokens.id');
            $table->string('device_id', 100)->nullable()->comment('معرّف الجهاز من التطبيق');
            $table->string('device_name', 120)->nullable();
            $table->string('platform', 16)->nullable()->comment('android / ios / web');
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('last_active_at')->nullable();
            $table->timestamps();

            $table->index(['freelancer_id', 'device_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freelancer_devices');
        Schema::dropIfExists('freelancers');
    }
};
