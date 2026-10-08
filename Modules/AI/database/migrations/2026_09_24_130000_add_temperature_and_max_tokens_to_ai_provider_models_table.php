<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Business gap fix: temperature/max_tokens used to live only on
     * ai_providers - one value per provider, shared by every model
     * registered under it, even though different models genuinely need
     * different settings (e.g. a reasoning-tagged model needing a much
     * higher max_tokens than a lightweight chat model, or a coding model
     * wanting a near-zero temperature while a general chat model stays
     * more creative). Both columns are nullable and default to null on
     * purpose: null means "inherit the provider's own setting", so every
     * model already registered keeps behaving exactly as before this
     * migration until an admin explicitly sets an override - see
     * AiGateway::applyModelOverrides() for where this is actually read.
     */
    public function up(): void
    {
        Schema::table('ai_provider_models', function (Blueprint $table) {
            $table->decimal('temperature', 3, 2)->nullable()->after('capabilities')
                ->comment('درجة الإبداع الخاصة بهذا الموديل تحديداً - فاضية = يرث إعداد المزود العام');

            $table->unsignedInteger('max_tokens')->nullable()->after('temperature')
                ->comment('الحد الأقصى للتوكنز الخاص بهذا الموديل تحديداً - فاضي = يرث إعداد المزود العام');
        });
    }

    public function down(): void
    {
        Schema::table('ai_provider_models', function (Blueprint $table) {
            $table->dropColumn(['temperature', 'max_tokens']);
        });
    }
};
