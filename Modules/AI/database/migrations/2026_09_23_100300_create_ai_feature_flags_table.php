<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * v2.0 requirements doc, 4.4: Feature Flags control whether a
     * provider, model or tool is active for a given country, domain and
     * environment - a kill switch the admin flips from a settings screen,
     * with no code deploy needed.
     */
    public function up(): void
    {
        Schema::create('ai_feature_flags', function (Blueprint $table) {
            $table->id();

            $table->string('key')->comment('اسم/معرف الـ flag للعرض في لوحة التحكم');
            $table->string('target_type', 20)->comment('provider / model / tool - إيه اللي الـ flag ده بيتحكم فيه');

            $table->foreignId('provider_id')->nullable()
                ->constrained('ai_providers')
                ->cascadeOnDelete()
                ->comment('لو target_type=provider أو model، المزود المستهدف - فاضي يعني كل المزودين');

            $table->string('model_key')->nullable()->comment('لو target_type=model، الموديل بالتحديد');
            $table->string('tool_key')->nullable()->comment('لو target_type=tool، مفتاح الأداة');

            $table->string('country_code', 2)->nullable()->comment('فاضي يعني كل الدول');
            $table->string('domain')->nullable()->comment('فاضي يعني كل التخصصات');
            $table->string('environment', 20)->default('production');

            $table->boolean('is_enabled')->default(true)
                ->comment('false = تعطيل صريح لهذا الهدف في هذا النطاق (kill switch)');

            $table->text('description')->nullable();

            $table->timestamps();

            $table->index(['target_type', 'is_enabled'], 'ai_feature_flags_target_enabled_index');
            $table->index(['country_code', 'domain', 'environment'], 'ai_feature_flags_scope_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_feature_flags');
    }
};
