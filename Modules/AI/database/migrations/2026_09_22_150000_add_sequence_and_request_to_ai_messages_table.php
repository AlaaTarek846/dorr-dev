<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ai_messages', function (Blueprint $table) {
            $table->unsignedInteger('sequence_number')->nullable()->after('conversation_id')
                ->comment('ترتيب الرسالة داخل المحادثة - مهم عشان الموديل يفهم السياق بالترتيب الصح');

            $table->unsignedBigInteger('request_id')->nullable()->after('sequence_number')
                ->comment('طلب الـ AI اللي أنتج الرسالة دي (لرسائل assistant فقط) - بيربطها بـ ai_requests');

            $table->foreign('request_id')
                ->references('id')->on('ai_requests')
                ->nullOnDelete();

            $table->index(['conversation_id', 'sequence_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_messages', function (Blueprint $table) {
            $table->dropForeign(['request_id']);
            $table->dropIndex(['conversation_id', 'sequence_number']);
            $table->dropColumn(['sequence_number', 'request_id']);
        });
    }
};
