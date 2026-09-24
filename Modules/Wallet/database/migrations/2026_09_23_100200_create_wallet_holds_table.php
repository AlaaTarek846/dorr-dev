<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hold/Capture — docs/wallet-structure.md §2.1. Reserves an amount
     * without moving money; capture() posts the real wallet_transactions
     * debit for the actual amount, release() just frees the reservation.
     * The one table allowed to be UPDATEd (status transitions) until it
     * reaches a final state.
     */
    public function up(): void
    {
        Schema::create('wallet_holds', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('wallet_id')->constrained('wallets')->restrictOnDelete();
            $table->string('bucket')->comment('withdrawable / spend_only — إجباري، بدون default');
            $table->bigInteger('amount_minor')->comment('المبلغ المحجوز الأصلي (التقديري)');
            $table->string('status')->default('active')->comment('active / captured / released / expired');
            $table->bigInteger('captured_amount_minor')->nullable()->comment('بيتملى وقت capture() بس');
            $table->string('reference_type')->nullable()->comment('withdrawal_request / booking...');
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reason_code')->nullable();
            $table->timestamp('expires_at')->nullable()->comment('صمام أمان (Orphan Hold). null = بيتقفل بقرار بزنس (زي موافقة/رفض أدمن) مش بتايمر');
            $table->string('idempotency_key')->nullable()->unique();
            $table->foreignId('wallet_transaction_id')->nullable()->constrained('wallet_transactions')->nullOnDelete()->comment('الصف الحقيقي الناتج عند capture()');
            $table->timestamps();

            $table->index(['wallet_id', 'status']);
            $table->index(['reference_type', 'reference_id']);
            $table->index(['status', 'expires_at']);
        });

        // MySQL-only — see the matching comment in the wallets migration.
        if (DB::getDriverName() === 'mysql') {
            DB::statement(<<<'SQL'
                ALTER TABLE wallet_holds
                    ADD CONSTRAINT wallet_holds_amount_positive CHECK (amount_minor > 0),
                    ADD CONSTRAINT wallet_holds_bucket_valid CHECK (bucket IN ('withdrawable', 'spend_only')),
                    ADD CONSTRAINT wallet_holds_status_valid CHECK (status IN ('active', 'captured', 'released', 'expired')),
                    ADD CONSTRAINT wallet_holds_captured_within_amount CHECK (captured_amount_minor IS NULL OR captured_amount_minor <= amount_minor)
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_holds');
    }
};
