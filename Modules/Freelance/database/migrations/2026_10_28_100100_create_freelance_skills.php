<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FL-102: the skills a freelancer lists and a client asks for — named in every language, tied to one or more
 * specialties. A freelancer may suggest a new one (`pending`) for the admin to approve, reject, or merge into an
 * existing one (`merged`, kept as an alias that resolves to its target).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freelance_skills', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 100)->unique();
            $table->string('status', 12)->default('active')->comment('active / inactive / pending / rejected / merged');
            $table->string('suggested_by_type', 12)->nullable()->comment('freelancer — who suggested it');
            $table->unsignedBigInteger('suggested_by_id')->nullable();
            $table->foreignId('merged_into_id')->nullable()->constrained('freelance_skills')->nullOnDelete();
            $table->string('reject_reason', 255)->nullable();
            $table->unsignedInteger('usage_count')->default(0)->comment('how many profiles / projects use it');
            $table->timestamps();
            $table->index(['status', 'id']);
            $table->index(['suggested_by_type', 'suggested_by_id', 'created_at'], 'freelance_skill_suggester_index');
        });

        Schema::create('freelance_skill_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('freelance_skill_id')->constrained('freelance_skills')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('name', 80);
            $table->string('normalized', 100)->comment('الاسم بعد التنظيف (حروف صغيرة، من غير تشكيل) لمنع التكرار');
            $table->unique(['freelance_skill_id', 'locale'], 'freelance_skill_locale_unique');
            $table->unique(['locale', 'normalized'], 'freelance_skill_name_unique');
        });

        Schema::create('freelance_skill_specialty', function (Blueprint $table) {
            $table->foreignId('freelance_skill_id')->constrained('freelance_skills')->cascadeOnDelete();
            $table->foreignId('freelance_specialty_id')->constrained('freelance_specialties')->cascadeOnDelete();
            $table->primary(['freelance_skill_id', 'freelance_specialty_id'], 'freelance_skill_specialty_primary');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freelance_skill_specialty');
        Schema::dropIfExists('freelance_skill_translations');
        Schema::dropIfExists('freelance_skills');
    }
};
