<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FL-706: the person a rating is about may object to it once it is out (staff decide: keep, hide the comment, or take the rating out of
 * the numbers). FL-702: a short satisfaction survey from each side after a project, with the reasons when not satisfied.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('freelance_reviews', function (Blueprint $table) {
            $table->timestamp('comment_hidden_at')->nullable()->after('published_at');
            $table->timestamp('excluded_at')->nullable()->after('comment_hidden_at')->comment('taken out of the numbers and the profile by staff');
        });

        Schema::create('freelance_review_objections', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('review_id')->unique()->constrained('freelance_reviews')->cascadeOnDelete();
            $table->foreignId('agreement_id')->constrained('freelance_agreements')->cascadeOnDelete();
            $table->string('objector_side', 12)->comment('the person the rating is about: client | freelancer');
            $table->string('reason', 12)->comment('abusive | false | unfair | other');
            $table->string('note', 500);
            $table->string('status', 16)->default('open')->comment('open | rejected | comment_hidden | rating_removed');
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('freelance_satisfaction_surveys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agreement_id')->constrained('freelance_agreements')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('freelance_projects')->cascadeOnDelete();
            $table->string('side', 12)->comment('who answered: client | freelancer');
            $table->unsignedTinyInteger('score')->comment('1..5');
            $table->json('reasons')->nullable()->comment('why not satisfied (score 3 or less)');
            $table->string('comment', 500)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['agreement_id', 'side']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freelance_satisfaction_surveys');
        Schema::dropIfExists('freelance_review_objections');
        Schema::table('freelance_reviews', function (Blueprint $table) {
            $table->dropColumn(['comment_hidden_at', 'excluded_at']);
        });
    }
};
