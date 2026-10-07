<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 (doc S3): an explicit, many-to-many conversation<->file
 * relationship. Inspection found `ai_files.conversation_id` (Phase 1) is
 * a one-to-one slot set once at upload time - the file it was ORIGINALLY
 * uploaded into, never reassignable - and
 * `AiFileEngine::process()`'s own checksum dedupe can silently hand back
 * an existing AiFile whose `conversation_id` still points at a totally
 * different, earlier conversation (a real, pre-existing gap this phase's
 * inspection surfaced and documents in the Final Report - see
 * AiConversationFileScope). This table is the many-to-many layer on top:
 * `ai_files.conversation_id`/`message_id` are left completely untouched
 * (still "the conversation/message this file was first uploaded into",
 * unchanged meaning, zero behavior change for any existing caller), and
 * this table is the new, explicit "this file is part of this
 * conversation's file context" relationship, attach/detach-able, and
 * capable of linking one file to more than one conversation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_conversation_files', function (Blueprint $table) {
            $table->id();

            $table->foreignId('conversation_id')
                ->constrained('ai_conversations')
                ->cascadeOnDelete()
                ->comment('المحادثة اللي الملف ده متاح فيها كسياق');

            $table->foreignId('file_id')
                ->constrained('ai_files')
                ->cascadeOnDelete()
                ->comment('الملف المرتبط - لو الملف نفسه اتمسح فعليًا (AiFile) تتمسح العلاقة دي معاه، بس العكس غير صحيح');

            // doc S4: ATTACHED does not imply searchable - AiConversationFileScope
            // still checks the underlying AiFile's own processing_status/chunk
            // state before treating it as retrievable. DETACHED rows are kept
            // (not deleted) so a re-attach is a cheap status flip, not a fresh
            // insert, and so the attach history survives for debugging.
            $table->string('status', 20)->default('attached')->comment('attached|detached - دورة حياة العلاقة نفسها (مش حالة معالجة الملف اللي هي في ai_files.processing_status)');

            // doc S3's own "polymorphic ownership patterns" instruction -
            // mirrors AiFile.owner_type/owner_id's own shape (never a bare
            // user_id) so "who attached this" works identically for a user
            // or a provider caller, exactly like every other actor-tracking
            // column in this module.
            $table->string('attached_by_type')->nullable()->comment('نوع مين عمل الربط (user/provider) - فاضي لو تم تلقائيًا عن طريق رفع الملف نفسه جوه المحادثة');
            $table->unsignedBigInteger('attached_by_id')->nullable()->comment('معرف مين عمل الربط');

            $table->timestamp('detached_at')->nullable()->comment('وقت الفصل - فاضي لحد ما يحصل detach');

            $table->timestamps();

            // doc S30: attaching the same file to the same conversation
            // twice must never create a duplicate relationship row - a
            // real DB constraint, not just "by convention". Re-attaching
            // after a detach is an UPDATE of this same unique row (status
            // flipped back to 'attached'), not a second insert.
            $table->unique(['conversation_id', 'file_id'], 'ai_conversation_files_conversation_file_unique');
            $table->index(['conversation_id', 'status']);
            $table->index('file_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_conversation_files');
    }
};
