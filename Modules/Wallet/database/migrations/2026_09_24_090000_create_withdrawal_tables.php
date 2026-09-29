<?php

use App\Enums\Status;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * docs/wallet-structure.md §6 + §7. Two additions found while building it
     * (both in wallet-tasks.md Phase 8): `withdrawal_requests.idempotency_key` +
     * `request_hash` — the same double-submit protection every other
     * money-moving request has — so a retried tap on "Withdraw" can never
     * open two requests.
     */
    public function up(): void
    {
        Schema::create('withdrawal_methods', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type')->comment('provider حالياً — Modules\Wallet\Support\OwnerType');
            $table->unsignedBigInteger('owner_id');
            $table->string('type')->comment('bank / mobile_wallet');
            $table->string('label')->nullable();
            $table->text('data')->comment('encrypted:array — IBAN/اسم البنك أو رقم محفظة الموبايل');
            $table->boolean('is_favorite')->default(false);
            $table->boolean('status')->default(Status::Active->value);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['owner_type', 'owner_id']);
        });

        Schema::create('withdrawal_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained('wallets')->restrictOnDelete();
            $table->foreignId('withdrawal_method_id')->constrained('withdrawal_methods')->restrictOnDelete();
            $table->bigInteger('amount_minor');
            $table->string('status')->default('pending')->comment('pending / approved / rejected');
            $table->text('note')->nullable()->comment('ملاحظة الأدمن عند القبول');
            $table->text('rejection_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('hold_id')->constrained('wallet_holds')->restrictOnDelete()->comment('الحجز اللي اتعمل وقت إنشاء الطلب');
            $table->string('idempotency_key')->unique();
            $table->char('request_hash', 64);
            $table->timestamps();

            $table->index(['wallet_id', 'status']);
            $table->index('status');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE withdrawal_requests ADD CONSTRAINT withdrawal_requests_amount_positive CHECK (amount_minor > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawal_requests');
        Schema::dropIfExists('withdrawal_methods');
    }
};
