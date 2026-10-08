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
        Schema::create('ai_subscriptions', function (Blueprint $table) {
            $table->id();

            $table->string('owner_type')->comment('نوع صاحب الاشتراك (alias قصير: user أو provider)');
            $table->unsignedBigInteger('owner_id')->comment('معرف صاحب الاشتراك في جدول الـ users أو الـ providers حسب owner_type');

            $table->foreignId('plan_id')
                ->constrained('ai_plans')
                ->restrictOnDelete()
                ->comment('الخطة المشترك فيها اليوزر/المقدم');

            $table->timestamp('starts_at')->comment('تاريخ بداية الاشتراك');
            $table->timestamp('ends_at')->nullable()->comment('تاريخ نهاية الاشتراك - فاضي يعني لسه شغال بدون تاريخ انتهاء محدد');
            $table->string('status')->default('active')->comment('حالة الاشتراك: active, inactive, expired, cancelled, suspended');

            $table->timestamps();

            $table->index(['owner_type', 'owner_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_subscriptions');
    }
};
