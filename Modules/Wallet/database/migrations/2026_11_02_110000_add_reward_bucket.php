<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A third bucket: rewards (Watch & Earn — docs/watch_earn_tasks.md WE-010, decision D2). Never withdrawn, never sent
 * to anyone, never expires; spent inside the app only. Kept apart from spend_only so what a reward funds (an ad
 * budget) comes back as a reward.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->bigInteger('reward_minor')->default(0)->after('spend_only_minor')->comment('رصيد المكافآت — لا يُسحب ولا يُحوَّل ولا ينتهي');
            $table->bigInteger('held_reward_minor')->default(0)->after('held_spend_only_minor');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE wallets ADD CONSTRAINT wallets_reward_non_negative CHECK (reward_minor >= 0), ADD CONSTRAINT wallets_held_reward_non_negative CHECK (held_reward_minor >= 0)');
            DB::statement('ALTER TABLE wallet_transactions DROP CHECK wallet_transactions_bucket_valid');
            DB::statement("ALTER TABLE wallet_transactions ADD CONSTRAINT wallet_transactions_bucket_valid CHECK (bucket IN ('withdrawable', 'spend_only', 'reward'))");
            DB::statement('ALTER TABLE wallet_holds DROP CHECK wallet_holds_bucket_valid');
            DB::statement("ALTER TABLE wallet_holds ADD CONSTRAINT wallet_holds_bucket_valid CHECK (bucket IN ('withdrawable', 'spend_only', 'reward'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE wallet_holds DROP CHECK wallet_holds_bucket_valid');
            DB::statement("ALTER TABLE wallet_holds ADD CONSTRAINT wallet_holds_bucket_valid CHECK (bucket IN ('withdrawable', 'spend_only'))");
            DB::statement('ALTER TABLE wallet_transactions DROP CHECK wallet_transactions_bucket_valid');
            DB::statement("ALTER TABLE wallet_transactions ADD CONSTRAINT wallet_transactions_bucket_valid CHECK (bucket IN ('withdrawable', 'spend_only'))");
            DB::statement('ALTER TABLE wallets DROP CHECK wallets_reward_non_negative, DROP CHECK wallets_held_reward_non_negative');
        }

        Schema::table('wallets', fn (Blueprint $table) => $table->dropColumn(['reward_minor', 'held_reward_minor']));
    }
};
