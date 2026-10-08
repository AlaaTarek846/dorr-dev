<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-plan limits for generated media, counted per calendar day of the
     * application timezone.
     *
     * image_daily_limit: NULL = unlimited (what every existing plan could already
     * do), 0 = the plan cannot generate images, N = at most N a day.
     * video_daily_limit / video_max_seconds: 0 = video generation is not part of
     * the plan (a new feature, so nothing gets it until the admin opts in).
     */
    public function up(): void
    {
        Schema::table('ai_plans', function (Blueprint $table) {
            $table->unsignedInteger('image_daily_limit')->nullable()->after('cooldown_minutes')
                ->comment('أقصى عدد صور يمكن توليدها/تعديلها في اليوم: NULL = بلا حد، 0 = غير مسموح');
            $table->unsignedInteger('video_daily_limit')->default(0)->after('image_daily_limit')
                ->comment('أقصى عدد فيديوهات في اليوم: 0 = توليد الفيديو غير متاح في الباقة');
            $table->unsignedInteger('video_max_seconds')->default(0)->after('video_daily_limit')
                ->comment('أقصى مدة للفيديو الواحد بالثواني (0 = غير متاح)');
        });
    }

    public function down(): void
    {
        Schema::table('ai_plans', function (Blueprint $table) {
            $table->dropColumn(['image_daily_limit', 'video_daily_limit', 'video_max_seconds']);
        });
    }
};
