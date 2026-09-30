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
        Schema::create('faqs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->nullable()
                ->constrained('service_categories')->nullOnDelete()->comment('الفئة الخدمية، فارغ يعني عام');
            $table->boolean('status')->default(Status::Active->value)->comment('الحالة');
            $table->unsignedInteger('sort_order')->default(0)->comment('ترتيب السؤال');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['service_id', 'sort_order']);
        });

        Schema::create('faq_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('faq_id')->constrained('faqs')->cascadeOnDelete()->comment('السؤال');
            $table->string('locale')->comment('اللغة');
            $table->string('question')->comment('نص السؤال');
            $table->text('answer')->comment('نص الإجابة');
            $table->timestamps();

            $table->unique(['faq_id', 'locale']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('faq_translations');
        Schema::dropIfExists('faqs');
    }
};
