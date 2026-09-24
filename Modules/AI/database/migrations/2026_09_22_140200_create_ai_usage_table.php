<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage', function (Blueprint $table) {
            $table->id()->comment('المعرف الفريد لسجل الاستهلاك.');
            $table->foreignId('request_id')
                ->constrained('ai_requests')
                ->cascadeOnDelete()
                ->comment('الطلب الذي يخص هذا الاستهلاك - يُحذف السجل تلقائياً عند حذف الطلب.');

            $table->unsignedInteger('input_tokens')->default(0)->comment('عدد التوكنز المستخدمة فى نص الطلب المُرسل للمزود.');
            $table->unsignedInteger('output_tokens')->default(0)->comment('عدد التوكنز المستخدمة فى الرد المُستلم من المزود.');
            $table->unsignedInteger('total_tokens')->default(0)->comment('إجمالي عدد التوكنز (المدخلات + المخرجات).');

            $table->decimal('input_cost', 12, 6)->default(0)->comment('التكلفة الفعلية لتوكنز المدخلات بحسب سعر المزود.');
            $table->decimal('output_cost', 12, 6)->default(0)->comment('التكلفة الفعلية لتوكنز المخرجات بحسب سعر المزود.');
            $table->decimal('total_cost', 12, 6)->default(0)->comment('إجمالي التكلفة الفعلية لهذا الطلب.');

            $table->string('usage_type')->default('actual')->comment('نوع القيمة المسجلة: estimated (تقديرية), actual (فعلية), adjustment (تسوية لاحقة).');
            $table->timestamps();

            $table->index(['request_id'], 'ai_usage_request_id_index');
            $table->index(['usage_type'], 'ai_usage_usage_type_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage');
    }
};
