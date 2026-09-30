<?php

use App\Enums\LegalPageType;
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
        Schema::create('legal_pages', function (Blueprint $table) {
            $table->id();
            $table->string('type')->default(LegalPageType::Privacy->value)->comment('نوع الصفحة القانونية (privacy/term/...)');
            $table->foreignId('service_id')->nullable()
                ->constrained('service_categories')->nullOnDelete()->comment('الفئة الخدمية، فارغ يعني عام');
            $table->boolean('status')->default(Status::Active->value)->comment('الحالة');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'service_id', 'status']);

            // At most one live page per (type, service). NULL deleted_at lets soft-deleted
            // rows free their slot when they are trashed; NULL service_id stays repeatable
            // (multiple general pages of the same type are allowed, matching the app rules).
            $table->unique(['type', 'service_id', 'deleted_at']);
        });

        Schema::create('legal_page_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('legal_page_id')->constrained('legal_pages')->cascadeOnDelete()->comment('الصفحة القانونية');
            $table->string('locale')->comment('اللغة');
            $table->longText('content')->comment('نص الصفحة');
            $table->timestamps();

            $table->unique(['legal_page_id', 'locale']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('legal_page_translations');
        Schema::dropIfExists('legal_pages');
    }
};