<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Universal AI File Engine - Phase 1 acceptance criteria doc, sections 2
 * ("disk", "extension", soft deletion) and 9 (storage path safety).
 * Additive only - nothing dropped or retyped.
 *
 * Deliberately NOT added here (reuse-not-duplicate decisions, see the
 * Phase 1 report): a `status` column distinct from `processing_status`
 * (the existing column already carries that single lifecycle), and
 * `user_id`/`company_id` columns (the existing `owner_type`/`owner_id`
 * polymorphic pair is this codebase's one tenant-boundary mechanism - a
 * grep of the whole module found no `company_id` concept anywhere).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_files', function (Blueprint $table) {
            $table->string('stored_name')->nullable()->after('file_name')
                ->comment('اسم الملف الفعلي على الـ storage (basename لـ file_path) - منفصل عن الاسم الأصلي اللي اليوزر رفعه');

            $table->string('extension', 20)->nullable()->after('mime_type')
                ->comment('امتداد الملف الأصلي (lowercase، من غير نقطة) - بيتفحص ضد قايمة الامتدادات الخطرة والمسموحة');

            $table->string('disk', 40)->default('public')->after('file_path')
                ->comment('اسم الـ Laravel filesystem disk اللي الملف متخزن عليه - يسمح بالتبديل لـ S3 لاحقًا من غير تغيير الكود');

            $table->softDeletes()
                ->comment('وقت الحذف المنطقي - الملف يفضل فى الجدول (audit) لكن مستبعد من الاستعلامات العادية');
        });
    }

    public function down(): void
    {
        Schema::table('ai_files', function (Blueprint $table) {
            $table->dropColumn(['stored_name', 'extension', 'disk', 'deleted_at']);
        });
    }
};
