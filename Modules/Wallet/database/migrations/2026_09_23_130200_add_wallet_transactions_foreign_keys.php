<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * wallet_transactions.fee_rule_id / payment_transaction_id were plain
     * columns until their target tables existed (Phase 2 → Phase 6). MySQL
     * only: SQLite can't add a foreign key to an existing table, and the
     * app-level writes already only ever set these from real rows.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->foreign('fee_rule_id')->references('id')->on('wallet_fee_rules')->nullOnDelete();
            $table->foreign('payment_transaction_id')->references('id')->on('payment_transactions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropForeign(['fee_rule_id']);
            $table->dropForeign(['payment_transaction_id']);
        });
    }
};
