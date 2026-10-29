<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** FL-506: a part ended after the work started needs both sides' word — who asked, when and why; and what went back to the client. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('freelance_milestones', function (Blueprint $table) {
            $table->string('cancel_requested_by', 12)->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->unsignedBigInteger('refunded_minor')->nullable()->comment('what went back to the client when the part was cancelled');
        });
    }

    public function down(): void
    {
        Schema::table('freelance_milestones', function (Blueprint $table) {
            $table->dropColumn(['cancel_requested_by', 'cancel_requested_at', 'cancel_reason', 'refunded_minor']);
        });
    }
};
