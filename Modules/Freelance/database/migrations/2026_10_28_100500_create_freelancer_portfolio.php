<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FL-105: a freelancer's portfolio — past work with images (private disk, shown through short-lived signed links), a
 * video link (YouTube / Vimeo only) and an outside link, each with its own visibility. A work shown outside the owner
 * needs their confirmation that they hold the rights; the admin can hide one that breaks the rules.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freelancer_portfolio_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('freelancer_id')->constrained('freelancers')->cascadeOnDelete();
            $table->string('title', 120);
            $table->string('description', 1000)->nullable();
            $table->date('completed_on')->nullable();
            $table->foreignId('freelance_specialty_id')->nullable()->constrained('freelance_specialties', 'id', 'fl_portfolio_specialty_fk')->nullOnDelete();
            $table->string('visibility', 12)->default('private')->comment('public / clients_only / private');
            $table->timestamp('rights_confirmed_at')->nullable()->comment('إقرار صاحب الحقوق');
            $table->string('external_url', 255)->nullable();
            $table->string('video_provider', 10)->nullable()->comment('youtube / vimeo');
            $table->string('video_id', 32)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('hidden_by_admin_at')->nullable();
            $table->string('hidden_reason', 255)->nullable();
            $table->foreignId('hidden_by_admin_id')->nullable()->constrained('admins', 'id', 'fl_portfolio_admin_fk')->nullOnDelete();
            $table->timestamps();
            $table->index(['freelancer_id', 'sort_order']);
            $table->index(['freelancer_id', 'visibility']);
        });

        Schema::create('freelancer_portfolio_item_skills', function (Blueprint $table) {
            $table->foreignId('portfolio_item_id')->constrained('freelancer_portfolio_items', 'id', 'fl_portfolio_skill_item_fk')->cascadeOnDelete();
            $table->foreignId('freelance_skill_id')->constrained('freelance_skills', 'id', 'fl_portfolio_skill_fk')->cascadeOnDelete();
            $table->primary(['portfolio_item_id', 'freelance_skill_id'], 'fl_portfolio_skill_primary');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freelancer_portfolio_item_skills');
        Schema::dropIfExists('freelancer_portfolio_items');
    }
};
