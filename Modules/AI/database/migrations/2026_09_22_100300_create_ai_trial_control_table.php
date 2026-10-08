<?php

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
        Schema::create('ai_trial_control', function (Blueprint $table) {
            $table->id();

            $table->string('owner_type')->comment('نوع صاحب سجل التجربة المجانية (alias قصير: user أو provider)');
            $table->unsignedBigInteger('owner_id')->comment('معرف صاحب السجل في جدول الـ users أو الـ providers حسب owner_type');

            $table->string('trial_status')->default('eligible')
                ->comment('حالة التجربة المجانية: eligible (مؤهل يبدأها) / active (شغالة دلوقتي) / ended (خلصت)');

            $table->string('abuse_status')->default('clear')
                ->comment('حالة الاشتباه في إساءة استخدام التجربة: clear (سليم) / flagged (تحت المراجعة) / blocked (اتمنع)');

            $table->text('abuse_reason')->nullable()->comment('سبب الاشتباه في إساءة الاستخدام - نص وصفي يظهر للأدمن في شاشة المراجعة');

            $table->timestamps();

            $table->unique(['owner_type', 'owner_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_trial_control');
    }
};
