<?php

use App\Enums\Status;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * docs/wallet-structure.md §4. `percent` is signed: positive = a fee the
     * platform keeps, negative = a bonus the platform gives (docs/wallet-plan.md §12).
     * No matching rule at runtime means percent = 0 — there is deliberately no
     * "default rule" row.
     */
    public function up(): void
    {
        Schema::create('wallet_fee_rules', function (Blueprint $table) {
            $table->id();
            $table->string('operation')->default('topup')->comment('topup بس دلوقتي');
            $table->foreignId('country_id')->nullable()->constrained('countries')->cascadeOnDelete()->comment('null = كل الدول');
            $table->foreignId('payment_method_id')->nullable()->constrained('payment_methods')->cascadeOnDelete()->comment('null = كل الطرق');
            $table->string('owner_type')->nullable()->comment('user / provider / null = الاتنين');
            $table->decimal('percent', 8, 4)->default(0)->comment('signed: موجب = رسوم، سالب = هدية');
            $table->bigInteger('min_amount_minor')->nullable()->comment('أقل قيمة رسوم/هدية لكل عملية');
            $table->bigInteger('max_amount_minor')->nullable()->comment('أقصى قيمة رسوم/هدية لكل عملية (cap)');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('max_uses_per_owner')->nullable()->comment('بيتعدّ من wallet_transactions بنفس fee_rule_id');
            $table->bigInteger('budget_total_minor')->nullable()->comment('أقصى تكلفة إجمالية للعرض');
            $table->bigInteger('budget_used_minor')->default(0);
            $table->unsignedInteger('priority')->default(0)->comment('الأعلى يكسب عند التعادل');
            $table->boolean('status')->default(Status::Active->value);
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['operation', 'country_id', 'payment_method_id', 'status'], 'wallet_fee_rules_lookup_index');
            $table->index(['starts_at', 'ends_at']);
        });

        Schema::create('wallet_fee_rule_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_fee_rule_id')->constrained('wallet_fee_rules')->cascadeOnDelete();
            $table->string('locale');
            $table->string('name');
            $table->string('description')->nullable();
            $table->timestamps();

            $table->unique(['wallet_fee_rule_id', 'locale'], 'wallet_fee_rule_translations_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_fee_rule_translations');
        Schema::dropIfExists('wallet_fee_rules');
    }
};
