<?php

use App\Enums\Status;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * docs/wallet-structure.md §8. `credentials` is `encrypted:array` on the
     * model and never appears in PaymentMethodResource.
     */
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique()->comment('myfatoorah_card, urpay_wallet, arb_card, manual_bank_transfer...');
            $table->string('gateway')->comment('مفتاح الـ driver: myfatoorah / arb / urpay / manual — PaymentGatewayRegistry');
            $table->string('type')->comment('online / manual');
            $table->boolean('is_global')->default(false)->comment('تظهر لكل الدول من غير صف في payment_method_country');
            $table->boolean('supports_topup')->default(true)->comment('احتياطي لعمليات تانية غير الشحن لاحقاً');
            $table->boolean('status')->default(Status::Active->value)->comment('الحالة');
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('credentials')->nullable()->comment('مشفّرة (encrypted:array). null لو type=manual. ما بتتعرضش في أي Resource أبداً');
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('payment_method_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_method_id')->constrained('payment_methods')->cascadeOnDelete();
            $table->string('locale');
            $table->string('name');
            $table->string('description')->nullable();
            $table->timestamps();

            $table->unique(['payment_method_id', 'locale'], 'payment_method_translations_unique');
        });

        Schema::create('payment_method_country', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_method_id')->constrained('payment_methods')->cascadeOnDelete();
            $table->foreignId('country_id')->constrained('countries')->cascadeOnDelete();
            $table->bigInteger('min_amount_minor')->nullable()->comment('Override اختياري فوق wallet_settings.min_topup_minor');
            $table->bigInteger('max_amount_minor')->nullable()->comment('Override اختياري');
            $table->boolean('status')->default(Status::Active->value)->comment('تعطيل مؤقت للطريقة في الدولة دي من غير حذف الربط');
            $table->timestamps();

            $table->unique(['payment_method_id', 'country_id'], 'payment_method_country_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_method_country');
        Schema::dropIfExists('payment_method_translations');
        Schema::dropIfExists('payment_methods');
    }
};
