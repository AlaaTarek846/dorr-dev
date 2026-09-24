<?php

use App\Enums\Status;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One settings row per country — never a single global row (see
     * docs/wallet-plan.md §14 for why "::first()" style settings caused bugs
     * in the reference apps). A row is created automatically for every
     * country by WalletSettingObserver.
     */
    public function up(): void
    {
        Schema::create('wallet_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->unique()->constrained('countries')->cascadeOnDelete()->comment('الدولة');
            $table->bigInteger('min_topup_minor')->nullable()->comment('أقل مبلغ شحن (بالفلس/الهللة)');
            $table->bigInteger('max_topup_minor')->nullable()->comment('أعلى مبلغ شحن');
            $table->bigInteger('min_withdrawal_minor')->nullable()->comment('أقل مبلغ سحب لكل طلب');
            $table->bigInteger('max_withdrawal_minor')->nullable()->comment('أعلى مبلغ سحب لكل طلب');
            $table->bigInteger('transfer_max_per_transaction_minor')->nullable()->comment('أقصى تحويل لكل عملية');
            $table->bigInteger('transfer_max_per_day_minor')->nullable()->comment('أقصى تحويل يومي');
            $table->bigInteger('transfer_max_per_month_minor')->nullable()->comment('أقصى تحويل شهري');
            $table->boolean('transfers_enabled')->default(false)->comment('تفعيل التحويل بين المستخدمين في هذه الدولة');
            $table->bigInteger('min_allowed_balance_provider_minor')->default(0)->comment('حد الدين signed لمقدم الخدمة، مثلاً -10000 = دين لحد 100 ريال');
            $table->bigInteger('min_allowed_balance_user_minor')->default(0)->comment('حد الدين signed للمستخدم (نتيجة عقوبات)');
            $table->boolean('status')->default(Status::Active->value)->comment('الحالة');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_settings');
    }
};
