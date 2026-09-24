<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_safety_scans', function (Blueprint $table) {
            $table->id()->comment('المعرف الفريد لعملية الفحص.');

            // عمود بسيط بدون Foreign Key لأن جدول ai_requests (Phase 7) لم يُبنى بعد في هذه المرحلة من التأسيس.
            $table->unsignedBigInteger('request_id')->nullable()->comment('معرف الطلب (ai_requests) الذي يخص هذا الفحص - سيُضاف عليه Foreign Key عند بناء Phase 7.');

            $table->string('owner_type')->nullable()->comment('نوع صاحب المحتوى المفحوص: user أو provider (Polymorphic).');
            $table->unsignedBigInteger('owner_id')->nullable()->comment('معرف صاحب المحتوى المفحوص (User أو Provider).');
            $table->index(['owner_type', 'owner_id'], 'ai_safety_scans_owner_type_owner_id_index');

            $table->string('target_type')->comment('نوع الهدف الذي تم فحصه: prompt, input, output, file, code, link.');
            $table->string('scan_type')->comment('نوع الفحص المنفذ: harm, injection, malware, secret, unsafe_code, unsafe_link.');
            $table->string('decision')->comment('القرار النهائي للفحص: passed, blocked, sanitized, review_required.');
            $table->json('findings')->nullable()->comment('تفاصيل النتائج التي اكتشفها الفحص بصيغة JSON - بيانات داخلية لا تُعرض لليوزر.');
            $table->timestamps();

            $table->index(['scan_type', 'decision'], 'ai_safety_scans_scan_type_decision_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_safety_scans');
    }
};
