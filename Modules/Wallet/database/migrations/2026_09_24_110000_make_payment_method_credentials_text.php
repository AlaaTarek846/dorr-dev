<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `credentials` is cast `encrypted:array`, so what reaches the column is an encrypted *string*, not
     * JSON. A MySQL `json` column rejects that ("Invalid JSON text"), which made it impossible to store
     * gateway credentials at all (SQLite, used by the tests, doesn't validate JSON, so it went unnoticed).
     * Same shape as `payment_transactions.gateway_context` and `withdrawal_methods.data`.
     */
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->text('credentials')->nullable()->comment('encrypted:array — مشفّرة، ما بتتعرضش في أي Resource أبداً. null لو ملهاش بيانات (sandbox/manual/لسه ما اتدخلتش)')->change();
        });
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->json('credentials')->nullable()->change();
        });
    }
};
