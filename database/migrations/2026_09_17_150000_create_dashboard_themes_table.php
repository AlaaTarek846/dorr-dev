<?php

use App\Enums\Status;
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
        Schema::create('dashboard_themes', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique()->comment('المعرّف الفريد');
            $table->string('path')->comment('مجلد الثيم تحت public/dashboard/themes/');
            $table->boolean('status')->default(Status::Active->value)->comment('الحالة');
            $table->boolean('is_default')->default(false)->comment('الثيم الافتراضي');
            $table->unsignedSmallInteger('sort_order')->default(0)->comment('ترتيب العرض');
            $table->timestamps();
            $table->index(['status', 'sort_order']);
            $table->softDeletes();
        });

        Schema::create('dashboard_theme_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dashboard_theme_id')
                ->constrained('dashboard_themes')
                ->cascadeOnDelete()
                ->comment('الثيم');
            $table->string('locale')->comment('اللغة');
            $table->string('name')->comment('اسم الثيم');
            $table->timestamps();

            $table->unique(['dashboard_theme_id', 'locale']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dashboard_theme_translations');
        Schema::dropIfExists('dashboard_themes');
    }
};
