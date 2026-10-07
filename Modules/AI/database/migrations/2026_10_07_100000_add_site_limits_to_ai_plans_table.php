<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Website builder allowance of a plan. 0 on either column = the plan does
     * not include the website builder (a new feature, so nothing has it until
     * the admin opts a plan in).
     */
    public function up(): void
    {
        Schema::table('ai_plans', function (Blueprint $table) {
            $table->unsignedInteger('site_projects_limit')->default(0)->after('video_max_seconds')
                ->comment('أقصى عدد مواقع يقدر العميل يملكها في نفس الوقت: 0 = مش متاح في الباقة');
            $table->unsignedInteger('site_daily_generations')->default(0)->after('site_projects_limit')
                ->comment('عدد مرات بناء/تعديل المواقع في اليوم: 0 = مش متاح في الباقة');
        });
    }

    public function down(): void
    {
        Schema::table('ai_plans', function (Blueprint $table) {
            $table->dropColumn(['site_projects_limit', 'site_daily_generations']);
        });
    }
};
