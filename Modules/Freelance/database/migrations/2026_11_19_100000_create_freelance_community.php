<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FL-L12: the professional community — a LinkedIn-like feed for freelancers only (clients never see it). A freelancer posts (a post, a
 * question or a finished work from their portfolio) with up to four images, others comment (one level of replies), react and save; they
 * follow each other. Contact details are refused like in the chat, there is a daily limit, members report what breaks the rules and the
 * staff hide it and may mute a member from posting for a while.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freelance_community_posts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('freelancer_id')->constrained('freelancers')->cascadeOnDelete();
            $table->foreignId('country_id')->constrained('countries')->cascadeOnDelete()->comment('the author\'s country: the feed is per country');
            $table->foreignId('freelance_specialty_id')->nullable()->constrained('freelance_specialties')->nullOnDelete();
            $table->string('kind', 12)->default('post')->comment('post | question | work');
            $table->text('body');
            $table->foreignId('portfolio_item_id')->nullable()->constrained('freelancer_portfolio_items')->nullOnDelete()->comment('kind work: the portfolio item shared');
            $table->unsignedBigInteger('accepted_comment_id')->nullable()->comment('kind question: the answer the author marked as the best');
            $table->unsignedInteger('reactions_count')->default(0);
            $table->unsignedInteger('comments_count')->default(0);
            $table->string('status', 12)->default('published')->comment('published | hidden');
            $table->string('hidden_reason', 300)->nullable();
            $table->unsignedBigInteger('hidden_by_admin_id')->nullable()->comment('null with status hidden = hidden on its own after enough reports');
            $table->timestamp('hidden_at')->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['country_id', 'status', 'id']);
            $table->index(['freelance_specialty_id', 'status', 'id']);
            $table->index(['freelancer_id', 'id']);
        });

        Schema::create('freelance_community_comments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('post_id')->constrained('freelance_community_posts')->cascadeOnDelete();
            $table->foreignId('freelancer_id')->constrained('freelancers')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('freelance_community_comments')->cascadeOnDelete()->comment('a reply to this comment (one level only)');
            $table->text('body');
            $table->unsignedInteger('reactions_count')->default(0);
            $table->unsignedInteger('replies_count')->default(0);
            $table->string('status', 12)->default('published')->comment('published | hidden');
            $table->string('hidden_reason', 300)->nullable();
            $table->unsignedBigInteger('hidden_by_admin_id')->nullable();
            $table->timestamp('hidden_at')->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['post_id', 'parent_id', 'id']);
        });

        Schema::create('freelance_community_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('freelancer_id')->constrained('freelancers')->cascadeOnDelete();
            $table->string('subject_type', 8)->comment('post | comment');
            $table->unsignedBigInteger('subject_id');
            $table->string('kind', 12)->comment('like | celebrate | insightful | support');
            $table->timestamps();

            $table->unique(['freelancer_id', 'subject_type', 'subject_id'], 'freelance_community_reaction_once');
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('freelance_community_saves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('freelancer_id')->constrained('freelancers')->cascadeOnDelete();
            $table->foreignId('post_id')->constrained('freelance_community_posts')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['freelancer_id', 'post_id']);
        });

        Schema::create('freelance_community_follows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('follower_id')->constrained('freelancers')->cascadeOnDelete();
            $table->foreignId('followed_id')->constrained('freelancers')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['follower_id', 'followed_id']);
            $table->index('followed_id');
        });

        Schema::create('freelance_community_reports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('reporter_id')->constrained('freelancers')->cascadeOnDelete();
            $table->string('subject_type', 8)->comment('post | comment');
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('author_id')->nullable()->constrained('freelancers')->nullOnDelete()->comment('who wrote what was reported');
            $table->string('reason', 16)->comment('spam | contact | abuse | off_topic | misleading | other');
            $table->string('details', 1000)->nullable();
            $table->string('status', 12)->default('open')->comment('open | upheld | dismissed');
            $table->string('decision_note', 1000)->nullable();
            $table->unsignedBigInteger('decided_by_admin_id')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'id']);
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('freelance_community_mutes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('freelancer_id')->constrained('freelancers')->cascadeOnDelete();
            $table->timestamp('ends_at')->comment('may read but not post, comment or react until then');
            $table->string('reason', 500);
            $table->unsignedBigInteger('admin_id');
            $table->timestamp('lifted_at')->nullable();
            $table->timestamps();

            $table->index(['freelancer_id', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freelance_community_mutes');
        Schema::dropIfExists('freelance_community_reports');
        Schema::dropIfExists('freelance_community_follows');
        Schema::dropIfExists('freelance_community_saves');
        Schema::dropIfExists('freelance_community_reactions');
        Schema::dropIfExists('freelance_community_comments');
        Schema::dropIfExists('freelance_community_posts');
    }
};
