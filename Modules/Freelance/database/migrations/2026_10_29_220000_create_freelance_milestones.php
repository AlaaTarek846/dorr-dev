<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** FL-402: the parts an agreement is paid in — one at least. The statuses beyond `unfunded` come with FL-403. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freelance_milestones', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('agreement_id')->constrained('freelance_agreements')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('freelance_projects')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('title', 120);
            $table->string('description', 500)->nullable();
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedSmallInteger('duration_days')->nullable();
            $table->unsignedBigInteger('client_fee_minor')->default(0);
            $table->unsignedBigInteger('commission_minor')->default(0);
            $table->unsignedBigInteger('net_minor')->default(0);
            $table->string('status', 20)->default('unfunded');
            $table->timestamps();
            $table->unique(['agreement_id', 'position']);
            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freelance_milestones');
    }
};
