<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * docs/wallet-structure.md §9 + 9.1 + 9.2. payment_transactions is the
     * mutable "where is this payment now" record; payment_gateway_logs and
     * webhook_inbox are append-only audit trails.
     *
     * Additions to the design found while building it (all documented in
     * wallet-tasks.md Phase 6): `redirect_url` (so an idempotent replay can
     * return the same link), `gateway_context` (encrypted — state a stateless
     * gateway driver needs on its next call, e.g. URPay's security token), and
     * a nullable payment_gateway_logs.payment_transaction_id (so a callback
     * for an unknown reference can still be logged instead of dropped).
     */
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('payment_method_id')->constrained('payment_methods')->restrictOnDelete();
            $table->string('owner_type')->comment('alias المالك (user/provider) — Modules\Wallet\Support\OwnerType');
            $table->unsignedBigInteger('owner_id');
            $table->foreignId('wallet_id')->nullable()->constrained('wallets')->nullOnDelete()->comment('بيتحدد وقت الاكتمال');
            $table->foreignId('country_id')->constrained('countries')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->bigInteger('requested_amount_minor')->comment('paid_amount — القيمة المحلية اللي المحفظة بتتحسب عليها دايماً');
            $table->string('status')->default('pending')->comment('pending / paid / failed / expired / refunded');
            $table->string('gateway_reference')->nullable()->index()->comment('معرف العملية عند البوابة');
            $table->string('gateway_invoice_id')->nullable();
            $table->text('redirect_url')->nullable();
            $table->foreignId('fee_rule_id')->nullable()->constrained('wallet_fee_rules')->nullOnDelete()->comment('لقطة القاعدة وقت الـ quote');
            $table->decimal('fee_percent', 8, 4)->nullable()->comment('لقطة النسبة');
            $table->bigInteger('quoted_net_amount_minor')->nullable()->comment('الصافي المتوقع للـ withdrawable (بعد الرسوم)');
            $table->bigInteger('quoted_bonus_amount_minor')->nullable()->comment('الهدية المتوقعة (spend_only)');
            $table->json('raw_request')->nullable()->comment('آخر طلب (لقطة سريعة) — التاريخ الكامل في payment_gateway_logs');
            $table->json('raw_response')->nullable();
            $table->text('gateway_context')->nullable()->comment('encrypted:array — حالة الـ driver بين الاستدعاءات');
            $table->string('failure_reason')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('reconciliation_attempts')->default(0);
            $table->timestamp('last_reconciled_at')->nullable();
            $table->string('idempotency_key')->unique();
            $table->char('request_hash', 64);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index(['owner_type', 'owner_id']);
            $table->index(['status', 'expires_at']);
        });

        Schema::create('webhook_inbox', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_method_id')->nullable()->constrained('payment_methods')->nullOnDelete();
            $table->foreignId('payment_transaction_id')->nullable()->constrained('payment_transactions')->nullOnDelete();
            $table->string('provider_code');
            $table->string('event_id')->comment('معرف الحدث عند البوابة (مش عندنا)');
            $table->boolean('valid_signature')->comment('نتيجة التحقق (فك التشفير / الاستعلام server-to-server) قبل أي معالجة');
            $table->json('payload');
            $table->char('payload_hash', 64);
            $table->string('status')->default('received')->comment('received / processing / processed / failed / ignored');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('received_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();

            $table->unique(['provider_code', 'event_id'], 'webhook_inbox_provider_event_unique');
        });

        Schema::create('payment_gateway_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_transaction_id')->nullable()->constrained('payment_transactions')->restrictOnDelete();
            $table->string('provider_code');
            $table->string('event')->comment('initiate / webhook / redirect_verify / manual_reconcile / refund_attempt');
            $table->string('direction')->comment('outbound / inbound');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->json('request_payload')->nullable()->comment('بدون أي بيانات سرية');
            $table->json('response_payload')->nullable();
            $table->string('gateway_status_reported')->nullable();
            $table->string('external_reference')->nullable()->comment('المرجع اللي وصل لو مفيش payment_transaction معروف');
            $table->string('triggered_by_type')->nullable();
            $table->unsignedBigInteger('triggered_by_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['payment_transaction_id', 'created_at']);
            $table->index('event');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement(<<<'SQL'
                ALTER TABLE payment_transactions
                    ADD CONSTRAINT payment_transactions_amount_positive CHECK (requested_amount_minor > 0)
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateway_logs');
        Schema::dropIfExists('webhook_inbox');
        Schema::dropIfExists('payment_transactions');
    }
};
