<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Immutable ledger — docs/wallet-structure.md §2. No `updated_at` on
     * purpose: a row is written once and never touched again. Corrections
     * are a new row with `reverses_transaction_id` set, never an UPDATE.
     */
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique()->comment('معرف خارجي آمن (مش تسلسلي) للـ API');
            $table->foreignId('wallet_id')->constrained('wallets')->restrictOnDelete();
            $table->uuid('operation_id')->comment('بيجمع كل الصفوف الناتجة عن نفس الحدث (شحن+رسوم، تحويل مرسل+مستقبل...)');
            $table->string('direction')->comment('credit / debit');
            $table->string('bucket')->comment('withdrawable / spend_only — إجباري، بدون default (فصل 10)');
            $table->string('type')->comment('راجع WalletTransactionType');
            $table->bigInteger('amount_minor')->comment('موجب دايماً؛ الاتجاه من direction');
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete()->comment('لقطة من المحفظة');
            $table->foreignId('country_id')->constrained('countries')->restrictOnDelete()->comment('لقطة من المحفظة');
            $table->bigInteger('balance_after_minor')->comment('رصيد الـ bucket ده بعد الصف مباشرة');
            $table->bigInteger('total_balance_after_minor')->comment('إجمالي المحفظة بعد الصف');
            $table->string('reference_type')->nullable()->comment('مرجع خارجي (حجز/طلب/فاتورة — لاحقاً)');
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('counterparty_wallet_id')->nullable()->constrained('wallets')->restrictOnDelete()->comment('محفظة الطرف التاني في التحويل');
            $table->foreignId('reverses_transaction_id')->nullable()->constrained('wallet_transactions')->restrictOnDelete()->comment('لو الصف ده عكس صف قديم');
            // No FK yet — wallet_fee_rules / payment_transactions don't exist
            // until later phases. Constraint gets added when those tables do.
            $table->unsignedBigInteger('fee_rule_id')->nullable()->comment('لو الصف رسوم/هدية شحن (wallet_fee_rules، المرحلة 6)');
            $table->decimal('fee_percent', 8, 4)->nullable()->comment('النسبة المطبّقة فعلياً (لقطة)');
            $table->unsignedBigInteger('payment_transaction_id')->nullable()->comment('لو الصف ناتج عن شحن أونلاين (payment_transactions، المرحلة 6)');
            $table->string('idempotency_key')->nullable()->unique()->comment('مفتاح منع التكرار (على الصف الرئيسي في العملية بس)');
            $table->char('request_hash', 64)->nullable()->comment('SHA-256 لمحتوى الطلب. نفس idempotency_key بـ hash مختلف = 409');
            $table->json('notes')->nullable()->comment('{"key": "...", "variables": {...}} — مفتاح ترجمة + متغيرات');
            $table->string('created_by_type')->nullable()->comment('system / admin / نفس صاحب المحفظة');
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['id', 'country_id', 'currency_id']);
            $table->index(['wallet_id', 'created_at']);
            $table->index('operation_id');
            $table->index(['reference_type', 'reference_id']);
            $table->index('type');
            $table->index('bucket');
            $table->index('payment_transaction_id');

            // Composite FK: forces this row's (country_id, currency_id) to
            // match the wallet it belongs to — enforced by the DB itself,
            // not just "copied" application-side (docs/wallet-structure.md §1).
            $table->foreign(['wallet_id', 'country_id', 'currency_id'])
                ->references(['id', 'country_id', 'currency_id'])
                ->on('wallets')
                ->restrictOnDelete();
        });

        // MySQL-only — see the matching comment in the wallets migration.
        if (DB::getDriverName() === 'mysql') {
            DB::statement(<<<'SQL'
                ALTER TABLE wallet_transactions
                    ADD CONSTRAINT wallet_transactions_amount_positive CHECK (amount_minor > 0),
                    ADD CONSTRAINT wallet_transactions_bucket_valid CHECK (bucket IN ('withdrawable', 'spend_only')),
                    ADD CONSTRAINT wallet_transactions_direction_valid CHECK (direction IN ('credit', 'debit'))
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
