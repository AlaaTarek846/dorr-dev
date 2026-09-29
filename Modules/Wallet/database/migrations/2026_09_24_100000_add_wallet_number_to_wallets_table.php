<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Wallet\Support\WalletNumber;

return new class extends Migration
{
    /**
     * A public number per wallet, so a transfer can target one specific wallet
     * (a person has one wallet per country). Existing wallets get theirs here;
     * new ones get it from Wallet::creating. Nullable at the DB level only so
     * this migration can add-then-backfill; the model guarantees it is set.
     */
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->string('wallet_number', 16)->nullable()->unique()->after('id');
        });

        DB::table('wallets')->whereNull('wallet_number')->orderBy('id')->each(function ($wallet) {
            do {
                $number = WalletNumber::generate();
            } while (DB::table('wallets')->where('wallet_number', $number)->exists());

            DB::table('wallets')->where('id', $wallet->id)->update(['wallet_number' => $number]);
        });
    }

    public function down(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->dropUnique(['wallet_number']);
            $table->dropColumn('wallet_number');
        });
    }
};
