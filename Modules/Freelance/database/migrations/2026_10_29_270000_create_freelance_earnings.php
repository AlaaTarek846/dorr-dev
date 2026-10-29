<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FL-505 / FL-509: every net amount a freelancer earned from a part of the work — released to the wallet at once, or pending until
 * `release_at` when the agreement's policy holds earnings for some days (`earnings.hold_days`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freelance_earnings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('freelancer_id')->constrained('freelancers')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('freelance_projects')->cascadeOnDelete();
            $table->foreignId('milestone_id')->constrained('freelance_milestones')->cascadeOnDelete();
            $table->foreignId('country_id')->constrained('countries');
            $table->string('kind', 20)->comment('approved · ended (a part ended with a share) · dispute');
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency_code', 3);
            $table->string('status', 12)->comment('pending · released · cancelled');
            $table->timestamp('release_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->unsignedBigInteger('wallet_transaction_id')->nullable();
            $table->string('key', 120)->unique()->comment('one earning per movement');
            $table->timestamps();
            $table->index(['freelancer_id', 'status']);
            $table->index(['status', 'release_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freelance_earnings');
    }
};
