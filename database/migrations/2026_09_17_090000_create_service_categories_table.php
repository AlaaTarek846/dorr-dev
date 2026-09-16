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
        Schema::create('service_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()
                ->constrained('service_categories')->nullOnDelete()->comment('الفئة الخدمية الأصلية');
            $table->boolean('requires_provider')->default(false)->comment('يتطلب الموفر');
            $table->boolean('status')->default(Status::Active->value)->comment('الحالة');
            $table->unsignedInteger('sort_order')->default(0)->comment('ترتيب الفئة الخدمية');
            $table->timestamps();
        });

        Schema::create('service_category_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_category_id')->constrained('service_categories')->cascadeOnDelete()->comment('الفئة الخدمية');
            $table->string('locale')->comment('اللغة');
            $table->string('name')->comment('اسم الفئة الخدمية');
            $table->timestamps();

            $table->unique(['service_category_id', 'locale']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_category_translations');
        Schema::dropIfExists('service_categories');
    }
};
