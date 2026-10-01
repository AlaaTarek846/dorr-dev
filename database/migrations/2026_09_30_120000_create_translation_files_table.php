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
        Schema::create('translation_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('language_id')->constrained('languages')->cascadeOnDelete()->comment('اللغة');
            $table->string('platform', 20)->comment('المنصة: backend أو vue أو android');
            $table->string('group', 50)->comment('مجموعة الترجمة، مثل api أو messages أو strings');
            $table->string('status', 20)->default('draft')->comment('draft = توجد مسودة، published = المنشور هو الأحدث');
            $table->char('checksum', 64)->nullable()->comment('SHA-256 لمحتوى آخر ملف محفوظ');
            $table->unsignedInteger('version')->default(0)->comment('يزيد مع كل نشر');
            $table->timestamp('published_at')->nullable()->comment('آخر نشر');
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete()->comment('أنشأه');
            $table->foreignId('updated_by')->nullable()->constrained('admins')->nullOnDelete()->comment('آخر من عدّله');
            $table->timestamps();

            $table->unique(['language_id', 'platform', 'group']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('translation_files');
    }
};
