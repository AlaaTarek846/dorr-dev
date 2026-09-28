<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Chat themes (admin-made wallpapers + bubble colours) and reports (docs/chat-plan.md §2, §5).
     * A report keeps a *copy* of the last messages: the evidence must survive the reporter or the
     * sender deleting them, and disappearing messages being purged.
     */
    public function up(): void
    {
        Schema::create('chat_themes', function (Blueprint $table) {
            $table->id();
            $table->string('background_color', 9)->nullable()->comment('لون الخلفية لو مفيش صورة');
            $table->string('sender_color', 9)->comment('فقاعة رسايلي');
            $table->string('receiver_color', 9)->comment('فقاعة رسايل الناس');
            $table->boolean('is_dark')->default(false)->comment('الخلفية غامقة → النص فاتح');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_default')->default(false)->comment('واحد بس');
            $table->boolean('status')->default(true);
            $table->timestamps();
        });

        Schema::create('chat_theme_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_theme_id')->constrained('chat_themes')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('name', 100);
            $table->timestamps();

            $table->unique(['chat_theme_id', 'locale']);
        });

        Schema::create('chat_report_types', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });

        Schema::create('chat_report_type_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_report_type_id')->constrained('chat_report_types')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('name', 150);
            $table->timestamps();

            $table->unique(['chat_report_type_id', 'locale']);
        });

        Schema::create('chat_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->string('reporter_type', 20);
            $table->unsignedBigInteger('reporter_id');
            $table->foreignId('report_type_id')->nullable()->constrained('chat_report_types')->nullOnDelete();
            $table->text('details')->nullable();
            $table->string('status', 20)->default('pending')->comment('pending / reviewing / resolved / dismissed');
            $table->text('admin_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['reporter_type', 'reporter_id']);
        });

        Schema::create('chat_report_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained('chat_reports')->cascadeOnDelete();
            $table->string('participant_type', 20);
            $table->unsignedBigInteger('participant_id');

            $table->index(['participant_type', 'participant_id']);
        });

        Schema::create('chat_report_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained('chat_reports')->cascadeOnDelete();
            $table->unsignedBigInteger('message_id')->nullable()->comment('ممكن الرسالة تتمسح، النسخة تفضل');
            $table->string('sender_type', 20)->nullable();
            $table->unsignedBigInteger('sender_id')->nullable();
            $table->string('type', 30);
            $table->text('body')->nullable();
            $table->json('attachments')->nullable()->comment('روابط الملفات وقت البلاغ');
            $table->timestamp('sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_report_messages');
        Schema::dropIfExists('chat_report_users');
        Schema::dropIfExists('chat_reports');
        Schema::dropIfExists('chat_report_type_translations');
        Schema::dropIfExists('chat_report_types');
        Schema::dropIfExists('chat_theme_translations');
        Schema::dropIfExists('chat_themes');
    }
};
