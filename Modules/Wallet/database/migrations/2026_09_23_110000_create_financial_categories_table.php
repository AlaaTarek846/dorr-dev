<?php

use App\Enums\Status;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The system's own income/expense ledger — separate from
     * wallet_transactions, which is per-owner. docs/wallet-structure.md §5.
     */
    public function up(): void
    {
        Schema::create('financial_categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique()->comment('مفتاح ثابت يُستخدم في الكود (topup_fee, promo_bonus_cost...) — الاسم المترجم للعرض بس');
            $table->string('type')->comment('income / expense');
            $table->boolean('is_system')->default(false)->comment('فئات الـ seeder — مينفعش تتمسح ولا يتغيّر الـ slug بتاعها');
            $table->boolean('status')->default(Status::Active->value)->comment('الحالة');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('financial_category_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('financial_category_id')->constrained('financial_categories')->cascadeOnDelete()->comment('الفئة المالية');
            $table->string('locale')->comment('اللغة');
            $table->string('name')->comment('اسم الفئة المالية');
            $table->timestamps();

            $table->unique(['financial_category_id', 'locale'], 'financial_category_translations_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_category_translations');
        Schema::dropIfExists('financial_categories');
    }
};
