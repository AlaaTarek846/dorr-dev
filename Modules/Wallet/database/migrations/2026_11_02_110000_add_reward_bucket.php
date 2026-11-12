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
        // Idempotent: MySQL DDL is not transactional, so a failed run leaves part of this applied. Databases imported
        // from a dump may also lack the original CHECK constraints — drop/add each one only when needed.
        Schema::table('wallets', function (Blueprint $table) {
            if (! Schema::hasColumn('wallets', 'reward_minor')) {
                $table->bigInteger('reward_minor')->default(0)->after('spend_only_minor')->comment('رصيد المكافآت — لا يُسحب ولا يُحوَّل ولا ينتهي');
            }
            if (! Schema::hasColumn('wallets', 'held_reward_minor')) {
                $table->bigInteger('held_reward_minor')->default(0)->after('held_spend_only_minor');
            }
        });

        if (DB::getDriverName() === 'mysql') {
            $this->addCheck('wallets', 'wallets_reward_non_negative', 'reward_minor >= 0');
            $this->addCheck('wallets', 'wallets_held_reward_non_negative', 'held_reward_minor >= 0');
            $this->replaceCheck('wallet_transactions', 'wallet_transactions_bucket_valid', "bucket IN ('withdrawable', 'spend_only', 'reward')");
            $this->replaceCheck('wallet_holds', 'wallet_holds_bucket_valid', "bucket IN ('withdrawable', 'spend_only', 'reward')");
        }
    }

    private function hasCheck(string $table, string $name): bool
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', $name)
            ->where('CONSTRAINT_TYPE', 'CHECK')
            ->exists();
    }

    private function addCheck(string $table, string $name, string $expression): void
    {
        if (! $this->hasCheck($table, $name)) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }

    private function replaceCheck(string $table, string $name, string $expression): void
    {
        if ($this->hasCheck($table, $name)) {
            DB::statement("ALTER TABLE {$table} DROP CHECK {$name}");
        }
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            $this->replaceCheck('wallet_holds', 'wallet_holds_bucket_valid', "bucket IN ('withdrawable', 'spend_only')");
            $this->replaceCheck('wallet_transactions', 'wallet_transactions_bucket_valid', "bucket IN ('withdrawable', 'spend_only')");
            foreach (['wallets_reward_non_negative', 'wallets_held_reward_non_negative'] as $check) {
                if ($this->hasCheck('wallets', $check)) {
                    DB::statement("ALTER TABLE wallets DROP CHECK {$check}");
                }
            }
        }

        Schema::table('wallets', fn (Blueprint $table) => $table->dropColumn(['reward_minor', 'held_reward_minor']));
    }
};
