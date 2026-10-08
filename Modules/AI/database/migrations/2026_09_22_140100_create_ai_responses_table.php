<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_responses', function (Blueprint $table) {
            $table->id()->comment('المعرف الفريد للرد.');
            $table->foreignId('request_id')
                ->unique()
                ->constrained('ai_requests')
                ->cascadeOnDelete()
                ->comment('الطلب الذي يخص هذا الرد - علاقة واحد لواحد، ويُحذف الرد تلقائياً عند حذف الطلب.');
            $table->longText('response')->nullable()->comment('نص الرد الفعلي الذي سيظهر لليوزر.');
            $table->string('finish_reason')->nullable()->comment('سبب توقف الموديل عن الرد: completed, length_limit, tool_call, error.');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_responses');
    }
};
