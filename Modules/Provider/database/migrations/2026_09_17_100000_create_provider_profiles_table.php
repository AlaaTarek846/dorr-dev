<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Enums\UserStatus;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('providers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable()->comment('الاسم');
            $table->string('email')->unique()->nullable()->comment('البريد الإلكتروني');
            $table->string('phone')->unique()->nullable()->comment('الهاتف');
            $table->string('gender')->nullable()->comment('الجنس');
            $table->foreignId('country_id')->nullable()->constrained('countries')->comment('الدولة');
            $table->timestamp('email_verified_at')->nullable()->comment('وقت التحقق من البريد الإلكتروني');
            $table->timestamp('phone_verified_at')->nullable()->comment('وقت التحقق من الهاتف');
            $table->string('password')->nullable()->comment('كلمة المرور');
            $table->string('status')->default(UserStatus::Active->value)->comment('active / inactive / blocked');
            $table->rememberToken()->nullable()->comment('رمز التذكرة');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('providers');
    }
};
