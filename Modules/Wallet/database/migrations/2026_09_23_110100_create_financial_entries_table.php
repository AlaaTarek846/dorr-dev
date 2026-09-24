<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('financial_categories')->restrictOnDelete();
            $table->string('type')->comment('income / expense — لازم يطابق category.type، يتفرض بالـ FinancialLedgerService');
            $table->bigInteger('amount_minor')->comment('بالفلس/الهللة، موجب دايماً');
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->foreignId('country_id')->nullable()->constrained('countries')->nullOnDelete()->comment('null = صف عام مش خاص بدولة');
            $table->date('entry_date');
            $table->string('description')->nullable();
            $table->json('notes')->nullable()->comment('{"key": "...", "variables": {...}} — مفتاح ترجمة + متغيرات');
            $table->string('reference_type')->nullable()->comment('مرجع حقيقي resolvable (Booking/WalletFeeRule...)، مش نص حر');
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('wallet_transaction_id')->nullable()->constrained('wallet_transactions')->nullOnDelete()->comment('لو الصف ناتج عن حركة محفظة (رسوم/هدية شحن)');
            $table->string('created_by_type')->nullable()->comment('admin / system — نفس نمط wallet_transactions.created_by_type');
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['category_id', 'entry_date']);
            $table->index('country_id');
            $table->index(['reference_type', 'reference_id']);
            $table->index('wallet_transaction_id');
        });

        // MySQL-only — SQLite (tests) doesn't support ALTER TABLE ADD CHECK;
        // the same guard lives in FinancialLedgerService.
        if (DB::getDriverName() === 'mysql') {
            DB::statement(<<<'SQL'
                ALTER TABLE financial_entries
                    ADD CONSTRAINT financial_entries_amount_positive CHECK (amount_minor > 0),
                    ADD CONSTRAINT financial_entries_type_valid CHECK (type IN ('income', 'expense'))
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_entries');
    }
};
