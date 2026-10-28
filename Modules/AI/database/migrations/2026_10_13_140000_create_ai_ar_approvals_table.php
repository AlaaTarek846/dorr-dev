<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A customer sends one pinned version of a design to one provider; the provider answers it.
        Schema::create('ai_ar_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('experience_id')->constrained('ai_ar_experiences')->cascadeOnDelete();
            $table->foreignId('experience_version_id')->constrained('ai_ar_experience_versions')->cascadeOnDelete();
            $table->unsignedBigInteger('provider_id')->index();
            $table->string('status', 20)->default('pending')->index(); // pending | approved | changes_requested | rejected | withdrawn
            $table->string('customer_note', 500)->nullable();
            $table->string('provider_note', 500)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_ar_approvals');
    }
};
