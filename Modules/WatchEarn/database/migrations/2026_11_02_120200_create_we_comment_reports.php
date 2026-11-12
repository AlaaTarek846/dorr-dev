<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D21 (owner, 2026-11-02): the advertiser can't delete comments on their ad — they report one, the admin decides
 * (remove it or keep it), and both the advertiser and the comment's author are told what was done.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('we_comment_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comment_id')->constrained('we_comments')->cascadeOnDelete();
            $table->foreignId('ad_id')->constrained('we_ads')->cascadeOnDelete();
            $table->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();
            $table->string('reason', 30);
            $table->string('note', 500)->nullable();
            $table->string('status', 10)->default('pending')->comment('pending | removed | kept');
            $table->string('decision_note', 500)->nullable()->comment('Sent to both sides');
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['comment_id', 'reporter_id']);
            $table->index(['status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('we_comment_reports');
    }
};
