<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per real model-sync run against a provider's live /models
     * API (manual "Sync Models" click, or the scheduled
     * php artisan ai:sync-models) - lets an admin actually see what
     * happened in a given sync (how many new models appeared, how many
     * existing ones were updated, how many disappeared and were marked
     * deprecated instead of deleted, how many came back) instead of only
     * being able to infer it indirectly from the current state of
     * ai_provider_models.
     */
    public function up(): void
    {
        Schema::create('ai_model_sync_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('provider_id')->constrained('ai_providers')->cascadeOnDelete()
                ->comment('مزود الذكاء الاصطناعي اللي اتعملله المزامنة دي');

            $table->timestamp('started_at')
                ->comment('وقت بداية عملية المزامنة');

            $table->timestamp('completed_at')->nullable()
                ->comment('وقت اكتمال المزامنة - فاضي لو المزامنة فشلت أو لسه شغالة');

            $table->unsignedInteger('models_found')->default(0)
                ->comment('عدد الموديلات اللي رجعتها استدعاء /models الحقيقية');

            $table->unsignedInteger('models_created')->default(0)
                ->comment('عدد الصفوف الجديدة اللي اتسجلت');

            $table->unsignedInteger('models_updated')->default(0)
                ->comment('عدد الصفوف الموجودة اللي اتحدثت (last_seen_at أو تصنيفها)');

            $table->unsignedInteger('models_deprecated')->default(0)
                ->comment('عدد الموديلات اللي كانت مسجلة وماظهرتش فى المزامنة دي فاتحطلها status=deprecated');

            $table->unsignedInteger('models_reactivated')->default(0)
                ->comment('عدد الموديلات اللي كانت deprecated ورجعت تظهر تانى فى المزامنة دي');

            $table->string('status')
                ->comment('succeeded | failed');

            $table->text('error_message')->nullable()
                ->comment('رسالة الخطأ لو المزامنة فشلت (فشل الاتصال بالمزود، إلخ)');

            $table->timestamps();

            $table->index(['provider_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_model_sync_logs');
    }
};
