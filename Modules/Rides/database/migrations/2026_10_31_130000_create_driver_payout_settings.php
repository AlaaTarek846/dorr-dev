<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The captain's payout safeguards (docs/rides_tasks.md D-216..D-224, D-299): international payouts off until asked
 * for and approved, and an emergency freeze (a lost phone) that stops every withdrawal until the team lifts it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_payout_settings', function (Blueprint $table) {
            $table->foreignId('driver_id')->primary()->constrained('drivers')->cascadeOnDelete();
            $table->string('international_status', 10)->default('none')->comment('none / pending / approved / rejected');
            $table->string('international_reason', 500)->nullable();
            $table->string('international_answer', 500)->nullable();
            $table->dateTime('international_requested_at')->nullable();
            $table->dateTime('international_answered_at')->nullable();
            $table->dateTime('frozen_at')->nullable()->comment('emergency freeze (D-299)');
            $table->string('frozen_reason', 160)->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_payout_settings');
    }
};
