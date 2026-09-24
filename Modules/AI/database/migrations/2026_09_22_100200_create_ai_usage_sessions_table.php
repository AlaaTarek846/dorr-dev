<?php

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
        Schema::create('ai_usage_sessions', function (Blueprint $table) {
            $table->id();

            $table->string('owner_type')->comment('نوع صاحب جلسة الاستخدام (alias قصير: user أو provider)');
            $table->unsignedBigInteger('owner_id')->comment('معرف صاحب الجلسة في جدول الـ users أو الـ providers حسب owner_type');

            $table->foreignId('subscription_id')
                ->nullable()
                ->constrained('ai_subscriptions')
                ->nullOnDelete()
                ->comment('الاشتراك اللي اتخصم منه استهلاك الجلسة دي - ممكن يبقى فاضي لو مفيش اشتراك مرتبط');

            $table->unsignedBigInteger('organization_id')->nullable()
                ->comment('معرف المؤسسة لو اليوزر شغال تحت مؤسسة - عمود عادي من غير foreign key دلوقتي لحد ما جدول organizations يتعمل (Phase 3)');

            $table->timestamp('started_at')->comment('وقت بداية جلسة الاستخدام');
            $table->timestamp('ended_at')->nullable()->comment('وقت نهاية الجلسة - فاضي يعني الجلسة لسه شغالة');
            $table->unsignedInteger('duration_seconds')->default(0)->comment('مدة الجلسة بالثواني - القيمة اللي بتتخصم من رصيد الخطة');

            $table->timestamps();

            $table->index(['owner_type', 'owner_id', 'started_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_usage_sessions');
    }
};
