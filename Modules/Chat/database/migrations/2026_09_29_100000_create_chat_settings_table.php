<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row of chat limits, edited from the admin dashboard (docs/chat-plan.md §10.0).
 * The row is created here, so the app can always rely on it existing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('max_group_members')->default(1024)->comment('أقصى عدد أعضاء في الجروب');
            $table->unsignedInteger('max_file_size_mb')->default(100)->comment('أقصى حجم ملف مرفق');
            $table->unsignedInteger('edit_window_minutes')->default(15)->comment('مدة تعديل الرسالة');
            $table->unsignedInteger('delete_for_everyone_window_minutes')->default(2880)->comment('مدة الحذف للكل');
            $table->unsignedInteger('deleted_message_retention_days')->default(30)->comment('مدة الاحتفاظ بالرسائل المحذوفة للكل');
            $table->unsignedInteger('max_folders')->default(10);
            $table->unsignedInteger('max_pinned_messages')->default(3);
            $table->unsignedInteger('max_forward_targets')->default(5);
            $table->unsignedInteger('story_duration_hours')->default(24);
            $table->unsignedInteger('story_video_max_seconds')->default(60);
            $table->unsignedInteger('max_call_participants')->default(8);
            $table->boolean('stories_enabled')->default(true);
            $table->boolean('calls_enabled')->default(true);
            $table->timestamps();
        });

        DB::table('chat_settings')->insert(['created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_settings');
    }
};
