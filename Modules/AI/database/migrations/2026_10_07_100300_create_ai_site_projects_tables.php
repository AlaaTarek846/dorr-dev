<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A customer's AI-built website. The files of every version live on the
     * private disk (ai-sites/{project}/v{n}/...), never in public/.
     */
    public function up(): void
    {
        Schema::create('ai_site_projects', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type');
            $table->unsignedBigInteger('owner_id');
            $table->string('title');
            $table->string('slug', 64)->unique()->comment('رمز عشوائي غير قابل للتخمين يظهر في رابط المعاينة');
            $table->string('status', 16)->default('draft')->comment('draft, generating, ready, failed');
            $table->string('access_type', 16)->comment('plan أو purchase');
            $table->foreignId('purchase_id')->nullable()->constrained('ai_site_purchases')->nullOnDelete();
            $table->json('brief')->comment('بيانات الفورم اللي بُني عليها الموقع');
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('disabled_at')->nullable()->comment('إيقاف من الأدمن');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['owner_type', 'owner_id']);
        });

        Schema::create('ai_site_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('ai_site_projects')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('kind', 16)->comment('generate, edit, restore');
            $table->string('status', 16)->default('pending')->comment('pending, processing, completed, failed');
            $table->text('instruction')->nullable();
            $table->json('files')->nullable()->comment('قائمة الملفات: path, size');
            $table->unsignedInteger('total_bytes')->default(0);
            $table->foreignId('provider_id')->nullable()->constrained('ai_providers')->nullOnDelete();
            $table->string('model_key')->nullable();
            $table->boolean('counted')->default(true)->comment('بتتحسب على الحد؟ (الفاشلة والاستعادة لا)');
            $table->text('error_message')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'number']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_site_versions');
        Schema::dropIfExists('ai_site_projects');
    }
};
