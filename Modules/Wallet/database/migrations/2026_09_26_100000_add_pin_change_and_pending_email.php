<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `must_change`: the PIN was reset to a well-known value (0000 after an approved recovery request), so the
     * owner has to pick a real one before it can move money.
     * `pending_email`: an e-mail chosen as the *new* recovery method, not confirmed yet — the old method
     * keeps working until the code is entered, so abandoning the change never leaves the wallet without one.
     */
    public function up(): void
    {
        Schema::table('wallet_pins', function (Blueprint $table) {
            $table->boolean('must_change')->default(false)->after('pin_hash')->comment('الـ PIN اتعمله reset لقيمة معروفة: لازم يتغيّر قبل أي عملية مالية');
        });

        Schema::table('wallet_recovery_methods', function (Blueprint $table) {
            $table->string('pending_email')->nullable()->after('email_verified_at')->comment('إيميل جديد لسه متأكدش — الطريقة القديمة تفضل شغالة لحد التأكيد');
        });
    }

    public function down(): void
    {
        Schema::table('wallet_recovery_methods', function (Blueprint $table) {
            $table->dropColumn('pending_email');
        });

        Schema::table('wallet_pins', function (Blueprint $table) {
            $table->dropColumn('must_change');
        });
    }
};
