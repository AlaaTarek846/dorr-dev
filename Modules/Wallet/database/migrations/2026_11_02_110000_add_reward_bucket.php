<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A third bucket: rewards (Watch & Earn — docs/watch_earn_tasks.md WE-010, decisions D2 / D20). Never withdrawn,
 * never expires; spent anywhere in the app, sent to others only where the country allows it. Kept apart from
 * spend_only so what a reward funds (an ad budget) comes back as a reward.
 *
 * Safe to run again after a half-done run (each step checks first), and on both MySQL 8 and MariaDB: a CHECK
 * constraint is dropped with `DROP CONSTRAINT` (MariaDB has no `DROP CHECK`).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('wallets', 'reward_minor')) {
            Schema::table('wallets', function (Blueprint $table) {
                $table->bigInteger('reward_minor')->default(0)->after('spend_only_minor')->comment('رصيد المكافآت — لا يُسحب ولا ينتهي');
            });
        }
        if (! Schema::hasColumn('wallets', 'held_reward_minor')) {
            Schema::table('wallets', function (Blueprint $table) {
                $table->bigInteger('held_reward_minor')->default(0)->after('held_spend_only_minor');
            });
        }

        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $this->addCheck('wallets', 'wallets_reward_non_negative', 'reward_minor >= 0');
        $this->addCheck('wallets', 'wallets_held_reward_non_negative', 'held_reward_minor >= 0');
        $this->replaceCheck('wallet_transactions', 'wallet_transactions_bucket_valid', "bucket IN ('withdrawable', 'spend_only', 'reward')");
        $this->replaceCheck('wallet_holds', 'wallet_holds_bucket_valid', "bucket IN ('withdrawable', 'spend_only', 'reward')");
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->replaceCheck('wallet_holds', 'wallet_holds_bucket_valid', "bucket IN ('withdrawable', 'spend_only')");
            $this->replaceCheck('wallet_transactions', 'wallet_transactions_bucket_valid', "bucket IN ('withdrawable', 'spend_only')");
            $this->dropCheck('wallets', 'wallets_reward_non_negative');
            $this->dropCheck('wallets', 'wallets_held_reward_non_negative');
        }

        Schema::table('wallets', fn (Blueprint $table) => $table->dropColumn(['reward_minor', 'held_reward_minor']));
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

    private function addCheck(string $table, string $name, string $rule): void
    {
        if (! $this->hasCheck($table, $name)) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$rule})");
        }
    }

    /** Some MariaDB versions don't list CHECKs in information_schema: try the drop anyway, and let "not there" pass. */
    private function dropCheck(string $table, string $name): void
    {
        try {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$name}");
        } catch (QueryException $e) {
            if ($this->hasCheck($table, $name)) {
                throw $e;
            }
        }
    }

    private function replaceCheck(string $table, string $name, string $rule): void
    {
        $this->dropCheck($table, $name);
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$rule})");
    }
};
