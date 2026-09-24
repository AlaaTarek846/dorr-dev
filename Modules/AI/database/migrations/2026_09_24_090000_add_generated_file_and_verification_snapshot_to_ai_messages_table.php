<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * These three columns are a per-message snapshot, written once when
     * the assistant message is created and never mutated afterwards. They
     * intentionally duplicate data that also lives in `ai_verifications`
     * (confidence_score) and `ai_document_generations` (the generated
     * file), because neither of those tables can be joined back to a
     * single message unambiguously: `ai_verifications` has no message_id
     * at all, and `ai_document_generations` has no message_id either, so
     * a conversation with several generated files has no reliable way to
     * tell which file belongs to which assistant reply except by storing
     * it on the message itself at creation time.
     */
    public function up(): void
    {
        Schema::table('ai_messages', function (Blueprint $table) {
            $table->json('generated_file')->nullable()->after('is_error')
                ->comment('لقطة ثابتة (name/url) من ai_document_generations وقت إنشاء الرسالة - مش علاقة حية');

            $table->decimal('confidence_score', 4, 3)->nullable()->after('generated_file')
                ->comment('لقطة من درجة الثقة اللي حسبها التحقق (ai_verifications.confidence_score) وقت إنشاء الرسالة');

            $table->json('verification_warnings')->nullable()->after('confidence_score')
                ->comment('تحذيرات التحقق اللي اتولدت وقت الرد ده (مش متخزنة في أي مكان تاني)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_messages', function (Blueprint $table) {
            $table->dropColumn(['generated_file', 'confidence_score', 'verification_warnings']);
        });
    }
};
