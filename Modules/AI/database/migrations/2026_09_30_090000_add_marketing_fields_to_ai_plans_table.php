<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Subscription system improvement pass (2026-09-30): turns a plan from
     * a bare price/duration row into something a pricing page can actually
     * sell - a badge ("الأكثر شيوعاً"/"الأفضل قيمة"), a short bullet list of
     * marketing features, a featured flag to visually highlight the plan
     * the business wants people to pick, and an optional "was" price so a
     * discount can be shown (original_price > price renders a strikethrough
     * + savings badge). All additive, all nullable/defaulted - no existing
     * row or behavior changes.
     */
    public function up(): void
    {
        Schema::table('ai_plans', function (Blueprint $table) {
            $table->string('badge')->nullable()->after('sort_order')
                ->comment('شارة تسويقية اختيارية تظهر فوق الخطة (مثال: "الأكثر شيوعاً")');
            $table->boolean('is_featured')->default(false)->after('badge')
                ->comment('تمييز الخطة بصريًا في صفحة الأسعار كالخطة الموصى بها');
            $table->json('features')->nullable()->after('is_featured')
                ->comment('قائمة نقاط تسويقية قصيرة تظهر تحت الخطة (JSON array of strings)');
            $table->decimal('original_price', 10, 2)->nullable()->after('price')
                ->comment('السعر قبل الخصم (اختياري) - لو أكبر من price بيظهر شطب + نسبة التوفير');
        });
    }

    public function down(): void
    {
        Schema::table('ai_plans', function (Blueprint $table) {
            $table->dropColumn(['badge', 'is_featured', 'features', 'original_price']);
        });
    }
};
