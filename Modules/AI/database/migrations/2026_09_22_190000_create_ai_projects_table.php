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
        Schema::create('ai_projects', function (Blueprint $table) {
            $table->id();

            // صاحب المشروع: ممكن يكون يوزر أو مقدم خدمة (alias قصير - شوف AIServiceProvider::boot)
            $table->string('owner_type')->comment('نوع صاحب المشروع (user أو provider)');
            $table->unsignedBigInteger('owner_id')->comment('معرف صاحب المشروع في جدول الـ users أو الـ providers حسب owner_type');

            $table->string('name')->comment('اسم المشروع اللي بيختاره اليوزر (مثال: تطوير موقع دور)');
            $table->text('description')->nullable()->comment('وصف مختصر للمشروع وهدفه');

            $table->json('settings')->nullable()->comment('إعدادات افتراضية للمشروع بصيغة JSON (مثال: اللغة الافتراضية، نبرة الرد)');

            $table->string('status')->default('active')
                ->comment('حالة المشروع: active (نشط) / archived (مؤرشف) / deleted (محذوف)');

            $table->timestamps();

            $table->index(['owner_type', 'owner_id'], 'ai_projects_owner_type_owner_id_index');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_projects');
    }
};
