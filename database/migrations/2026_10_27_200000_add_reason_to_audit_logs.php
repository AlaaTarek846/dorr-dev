<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why a sensitive decision was taken (account verification, docs/verification_tasks.md AV-005):
 * a rejection, a suspended badge, a withdrawn exemption — each keeps the reason the staff gave.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('reason', 1000)->nullable()->after('after')->comment('سبب القرار كما كتبه الموظف');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropColumn('reason');
        });
    }
};
