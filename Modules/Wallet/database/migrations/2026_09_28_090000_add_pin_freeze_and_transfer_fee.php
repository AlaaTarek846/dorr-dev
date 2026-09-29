<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `wallet_pins.frozen_at`: a *permanent* lock, distinct from the 15-minute `locked_until` — set after a
     * wrong PIN attempt that comes right after a temporary lock has already been served once. Nothing but an
     * admin-approved identity check (selfie + ID, {@see PinRecoveryReason::SecurityFreeze}) clears it.
     *
     * `pin_recovery_requests.reason`: the *same* admin review screen now serves two different situations —
     * a normal "I forgot my PIN, here's my document again" request, and a security freeze that needs a selfie
     * (+ an ID photo, whatever the owner's configured recovery method is) instead.
     *
     * `wallet_settings.transfer_fee_percent` / `transfer_fee_payer`: DORR's optional cut of a user-to-user
     * transfer. 0% by default (no fee); the rate actually applied is snapshotted onto the transaction itself
     * (`wallet_transactions.fee_percent`, already in the schema) so it can change later without rewriting history.
     */
    public function up(): void
    {
        Schema::table('wallet_pins', function (Blueprint $table) {
            $table->timestamp('frozen_at')->nullable()->after('locked_until')->comment('تجميد دائم بعد محاولة خاطئة تالية لقفل مؤقت — يُفك فقط بمراجعة إدارية');
        });

        Schema::table('pin_recovery_requests', function (Blueprint $table) {
            $table->string('reason', 20)->default('recovery_document')->after('method')->comment('recovery_document | security_freeze');
        });

        Schema::table('wallet_settings', function (Blueprint $table) {
            $table->decimal('transfer_fee_percent', 8, 4)->default(0)->after('transfers_enabled')->comment('نسبة رسوم التحويل بين المستخدمين، 0 = بدون رسوم');
            $table->string('transfer_fee_payer', 10)->default('recipient')->after('transfer_fee_percent')->comment('sender = تُضاف فوق المبلغ | recipient = تُخصم مما يستلمه');
        });
    }

    public function down(): void
    {
        Schema::table('wallet_settings', function (Blueprint $table) {
            $table->dropColumn(['transfer_fee_percent', 'transfer_fee_payer']);
        });

        Schema::table('pin_recovery_requests', function (Blueprint $table) {
            $table->dropColumn('reason');
        });

        Schema::table('wallet_pins', function (Blueprint $table) {
            $table->dropColumn('frozen_at');
        });
    }
};
