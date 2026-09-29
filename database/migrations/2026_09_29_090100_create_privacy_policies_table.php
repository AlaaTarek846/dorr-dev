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
        Schema::create('privacy_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->nullable()
                ->constrained('service_categories')->nullOnDelete()->comment('الفئة الخدمية، فارغ يعني عام');
            $table->boolean('status')->default(Status::Active->value)->comment('الحالة');
            $table->unsignedInteger('sort_order')->default(0)->comment('ترتيب سياسة الخصوصية');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['service_id', 'sort_order']);
        });

        Schema::create('privacy_policy_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('privacy_policy_id')->constrained('privacy_policies')->cascadeOnDelete()->comment('سياسة الخصوصية');
            $table->string('locale')->comment('اللغة');
            $table->longText('content')->comment('نص السياسة');
            $table->timestamps();

            $table->unique(['privacy_policy_id', 'locale']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('privacy_policy_translations');
        Schema::dropIfExists('privacy_policies');
    }
};
