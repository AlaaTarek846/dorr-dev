<?php

use App\Enums\Status;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique()->comment('كود الدولة ISO');
            $table->string('code_alpha3')->nullable()->comment('كود الدولة ISO ثلاثي');
            $table->string('dial_code')->comment('مفتاح الاتصال الدولي');
            $table->string('phone_starts_with')->nullable()->comment('أول رقم (أو أرقام) يبدأ بها رقم الهاتف بعد مفتاح الدولة');
            $table->integer('phone_length')->unsigned()->nullable()->comment('عدد أرقام الهاتف المحلي (بدون مفتاح الدولة)');
            $table->boolean('is_default')->default(Status::Inactive->value)->comment('الدولة الافتراضية');
            $table->foreignId('flag_id')->constrained('flags')->comment('العلم');
            $table->foreignId('currency_id')->constrained('currencies')->comment('العملة');
            $table->boolean('status')->default(Status::Active->value)->comment('الحالة');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('country_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->constrained('countries')->comment('الدولة');
            $table->string('locale')->comment('اللغة');
            $table->string('name')->comment('اسم الدولة');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('country_translations');
        Schema::dropIfExists('countries');
    }
};
