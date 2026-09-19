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
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('symbol')->comment('رمز العملة');
            $table->integer('decimal_places')->unsigned()->default(2)->comment('عدد الخانات العشرية');
            $table->decimal('exchange_rate', 18, 8)->default(1.00000000)->comment('سعر الصرف مقابل العملة الأساسية');
            $table->boolean('is_default')->default(Status::Inactive->value)->comment('العملة الافتراضية');
            $table->boolean('status')->default(Status::Active->value)->comment('الحالة');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('currency_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('currency_id')->constrained('currencies')->comment('العملة');
            $table->string('locale')->comment('اللغة');
            $table->string('name')->comment('اسم العملة');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('currency_translations');
        Schema::dropIfExists('currencies');
    }
};
