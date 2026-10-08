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
        Schema::create('ai_knowledge_sources', function (Blueprint $table) {
            $table->id();

            $table->foreignId('file_id')->nullable()
                ->constrained('ai_files')
                ->nullOnDelete()
                ->comment('الملف الأصلي اللي المصدر ده اتحول منه (ممكن يكون فاضي لمصدر معرفة مش مرتبط بملف)');

            // صاحب المصدر ده ممكن يكون أوسع من user/provider العاديين (user/organization/project/system)
            // فمش بنستخدم نفس الـ morph map القياسي، والقيمة system مفهاش owner_id
            $table->string('owner_type')->comment('نوع ملك مصدر المعرفة: user / organization / project / system');
            $table->unsignedBigInteger('owner_id')->nullable()->comment('معرف الملك - بيفضل فاضي لو owner_type=system');

            $table->string('name')->comment('اسم مصدر المعرفة للعرض في لوحة تحكم الأدمن');

            $table->string('data_classification')->default('internal')
                ->comment('تصنيف حساسية البيانات: public / internal / confidential / personal / secret / unverified - بيحدد مين يقدر يشوفه');

            $table->json('access_scope')->nullable()->comment('قائمة الصلاحيات المسموح لها بالوصول لهذا المصدر (مثال: role:employee)');

            $table->unsignedInteger('current_version')->default(1)->comment('رقم النسخة الحالية من المصدر');
            $table->boolean('change_detected')->default(false)->comment('هل اتغير الملف الأصلي ولسه محتاج نسخة جديدة؟');
            $table->boolean('is_active')->default(true)->comment('هل المصدر ده مفعّل ومتاح للموديل يرجع له؟');

            $table->timestamps();

            $table->index(['owner_type', 'owner_id'], 'ai_knowledge_sources_owner_type_owner_id_index');
            $table->index('data_classification');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_knowledge_sources');
    }
};
