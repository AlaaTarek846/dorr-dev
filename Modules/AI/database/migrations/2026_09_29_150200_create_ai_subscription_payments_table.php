<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * AI subscription billing (professional pass, 2026-09-29): a full,
     * independent ledger of every money movement the subscription system
     * ever caused - the initial purchase, every renewal (success or
     * failure), and every upgrade/downgrade proration charge or credit.
     * This is deliberately its own table rather than just reading
     * wallet_transactions back, for two reasons: (1) a *failed* renewal
     * attempt (insufficient balance) never produces a wallet_transactions
     * row at all - there is nothing to charge - but it absolutely must be
     * recorded somewhere so support/the owner can see why a subscription
     * went into grace/suspended; (2) it snapshots plan_id/amount/type in
     * one place instead of the admin ever having to reverse-engineer
     * "which wallet_transactions rows together made up one subscription
     * event" from operation_id alone.
     */
    public function up(): void
    {
        Schema::create('ai_subscription_payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('subscription_id')->constrained('ai_subscriptions')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('ai_plans')->restrictOnDelete()
                ->comment('الخطة وقت هذه الحركة بالذات - snapshot، مش بالضرورة نفس خطة الاشتراك الحالية لو اتغيرت بعد كده');

            $table->string('owner_type')->comment('نوع صاحب الاشتراك (alias قصير: user أو provider)');
            $table->unsignedBigInteger('owner_id');

            $table->string('type')->comment('initial | renewal | upgrade | downgrade');
            $table->string('status')->comment('succeeded | failed');

            $table->decimal('amount', 10, 2)->comment('المبلغ (موجب = خصم فعلي من المحفظة، سالب = رصيد اتزاد للمحفظة - حالة تنزيل الخطة)');
            $table->string('currency', 3)->comment('كود العملة وقت الحركة');

            $table->string('wallet_operation_id')->nullable()
                ->comment('operation_id المشترك بين صف/صفّي wallet_transactions الفعليين لو الحركة نجحت - ممكن يبقى صفّين (spend_only + withdrawable) بنفس الـ operation_id لو المبلغ اتقسم بين الفلاجين');
            $table->string('failure_reason')->nullable()
                ->comment('سبب الفشل لو status=failed (مثلاً: insufficient_balance)');

            $table->timestamps();

            $table->index(['owner_type', 'owner_id', 'created_at']);
            $table->index(['subscription_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_subscription_payments');
    }
};
