<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_safety_events', function (Blueprint $table) {
            $table->id()->comment('المعرف الفريد لحدث الأمان.');

            $table->string('owner_type')->nullable()->comment('نوع صاحب الطلب الذي حدث بشأنه هذا الحدث: user أو provider (Polymorphic).');
            $table->unsignedBigInteger('owner_id')->nullable()->comment('معرف صاحب الطلب (User أو Provider) المرتبط بهذا الحدث.');
            $table->index(['owner_type', 'owner_id'], 'ai_safety_events_owner_type_owner_id_index');

            // عمود بسيط بدون Foreign Key لأن جدول ai_requests (Phase 7) لم يُبنى بعد في هذه المرحلة من التأسيس.
            $table->unsignedBigInteger('request_id')->nullable()->comment('معرف الطلب (ai_requests) المرتبط بهذا الحدث - سيُضاف عليه Foreign Key عند بناء Phase 7.');

            $table->foreignId('safety_policy_id')
                ->nullable()
                ->constrained('ai_safety_policies')
                ->nullOnDelete()
                ->comment('سياسة الأمان التي تسببت في هذا الحدث، إن وجدت.');
            $table->foreignId('safety_rule_id')
                ->nullable()
                ->constrained('ai_safety_rules')
                ->nullOnDelete()
                ->comment('قاعدة الأمان المحددة التي تسببت في هذا الحدث، إن وجدت.');

            $table->string('action_taken')->comment('الإجراء الذي تم تنفيذه فعلياً: allow, block, review, require_confirmation, sanitize.');
            $table->text('reason')->nullable()->comment('سبب اتخاذ هذا القرار، نص مختصر يمكن عرضه لليوزر أو للأدمن.');
            $table->timestamps();

            $table->index(['created_at'], 'ai_safety_events_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_safety_events');
    }
};
