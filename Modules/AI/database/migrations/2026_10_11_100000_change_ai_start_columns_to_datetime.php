<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * على MySQL/MariaDB لما explicit_defaults_for_timestamp يكون OFF، أول عمود timestamp NOT NULL
 * من غير default بياخد تلقائياً DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP،
 * يعني وقت البداية بيتغير لـ now() مع أي تعديل على الصف. التحويل لـ dateTime بيشيل السلوك ده.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ai_subscriptions', function (Blueprint $table) {
            $table->dateTime('starts_at')->comment('تاريخ بداية الاشتراك')->change();
        });

        Schema::table('ai_usage_sessions', function (Blueprint $table) {
            $table->dateTime('started_at')->comment('وقت بداية جلسة الاستخدام')->change();
        });

        Schema::table('ai_model_sync_logs', function (Blueprint $table) {
            $table->dateTime('started_at')->comment('وقت بداية عملية المزامنة')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_subscriptions', function (Blueprint $table) {
            $table->timestamp('starts_at')->comment('تاريخ بداية الاشتراك')->change();
        });

        Schema::table('ai_usage_sessions', function (Blueprint $table) {
            $table->timestamp('started_at')->comment('وقت بداية جلسة الاستخدام')->change();
        });

        Schema::table('ai_model_sync_logs', function (Blueprint $table) {
            $table->timestamp('started_at')->comment('وقت بداية عملية المزامنة')->change();
        });
    }
};
