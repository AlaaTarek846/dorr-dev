<?php

use App\Enums\Status;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One wallet per (owner, country) — docs/wallet-structure.md §1. Balances
     * are stored (Hybrid model, wallet-plan.md §11), always in minor units
     * (fils/halalas), and only ever change through WalletService.
     */
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type')->comment('alias المالك (user/provider/platform) — ليس اسم class، راجع Modules\Wallet\Support\OwnerType');
            $table->unsignedBigInteger('owner_id')->comment('معرف المالك. platform ثابت = 0');
            $table->foreignId('country_id')->constrained('countries')->restrictOnDelete()->comment('دولة المحفظة');
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete()->comment('عملة المحفظة — لقطة وقت الإنشاء، لا تتغيّر بعد كده');
            $table->bigInteger('withdrawable_minor')->default(0)->comment('الرصيد القابل للسحب (ممكن يبقى سالب لو مسموح دين)');
            $table->bigInteger('spend_only_minor')->default(0)->comment('رصيد Hold — استخدام داخل التطبيق بس');
            $table->bigInteger('held_withdrawable_minor')->default(0)->comment('محجوز من withdrawable بحجوزات نشطة (wallet_holds)');
            $table->bigInteger('held_spend_only_minor')->default(0)->comment('محجوز من spend_only بحجوزات نشطة');
            $table->boolean('status')->default(Status::Active->value)->comment('تجميد المحفظة (أدمن)');
            $table->timestamp('last_reconciled_at')->nullable()->comment('آخر مرة أمر wallet:reconcile أكّد إن الرصيد مطابق للحركات');
            $table->timestamps();

            $table->unique(['owner_type', 'owner_id', 'country_id']);
            // Composite key so wallet_transactions/wallet_holds can FK against
            // (wallet_id, country_id, currency_id) and let the DB itself
            // reject a mismatched country/currency on the child row.
            $table->unique(['id', 'country_id', 'currency_id']);
            $table->index(['owner_type', 'owner_id']);
        });

        // MySQL-only: SQLite (used in tests, see phpunit.xml) doesn't support
        // adding CHECK constraints via ALTER TABLE. The equivalent guards
        // live in WalletService so behaviour is identical either way — this
        // is defense-in-depth against anything that bypasses the app.
        if (DB::getDriverName() === 'mysql') {
            DB::statement(<<<'SQL'
                ALTER TABLE wallets
                    ADD CONSTRAINT wallets_spend_only_non_negative CHECK (spend_only_minor >= 0),
                    ADD CONSTRAINT wallets_held_withdrawable_non_negative CHECK (held_withdrawable_minor >= 0),
                    ADD CONSTRAINT wallets_held_spend_only_non_negative CHECK (held_spend_only_minor >= 0)
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
