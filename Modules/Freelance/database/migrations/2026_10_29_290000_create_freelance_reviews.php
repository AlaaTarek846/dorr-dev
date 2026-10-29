<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FL-701: after a project closes each side rates the other by criteria. The stars and the comment live in the shared `ratings` table
 * (rateable = the agreement); this keeps who is rated, the score of each criterion, and when it shows — both at once, when the second
 * side has rated or the rating days are over, so nobody answers the other's rating.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freelance_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rating_id')->constrained('ratings')->cascadeOnDelete();
            $table->foreignId('agreement_id')->constrained('freelance_agreements')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('freelance_projects')->cascadeOnDelete();
            $table->string('side', 12)->comment('who rated: client · freelancer');
            $table->foreignId('freelancer_id')->constrained('freelancers')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['agreement_id', 'side']);
            $table->index(['freelancer_id', 'side', 'published_at']);
        });

        Schema::create('freelance_rating_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rating_id')->constrained('ratings')->cascadeOnDelete();
            $table->string('criterion', 32);
            $table->unsignedTinyInteger('stars')->comment('1..5');
            $table->unique(['rating_id', 'criterion']);
            $table->index('criterion');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freelance_rating_scores');
        Schema::dropIfExists('freelance_reviews');
    }
};
