<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FS-211: the paid plan of a freelancers' team (Team Starter and the ones after it). A plan now has an `audience` - `freelancer` (what
 * FL-L01 sold until now) or `team` - and a team plan adds the room it opens: how many people the team holds and how many projects it runs
 * at once (`null` = no cap). A team is subscribed by its Owner out of his own wallet (D7): one row per team and period in
 * `freelance_team_subscriptions`, the same shape as a freelancer's, and one invoice per charge in `freelance_team_subscription_invoices`.
 * Nothing in the freelancers' tables changes except the two new plan columns; a team with no live subscription is under the country's policy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('freelance_plans', function (Blueprint $table): void {
            $table->string('audience', 10)->default('freelancer')->after('slug')->comment('freelancer | team');
            $table->unsignedSmallInteger('team_members_max')->nullable()->after('services_max')->comment('team plans: people in the team; null = no cap');
            $table->unsignedSmallInteger('team_projects_max')->nullable()->after('team_members_max')->comment('team plans: projects running at once; null = no cap');
            $table->index(['audience', 'rank']);
        });

        Schema::create('freelance_team_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('team_id')->constrained('freelance_teams')->cascadeOnDelete();
            // The Owner who pays, out of his own wallet (D7). If he stops being an Owner the plan ends at its next renewal.
            $table->foreignId('paid_by_freelancer_id')->constrained('freelancers')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('freelance_plans');
            $table->foreignId('country_id')->constrained('countries');
            $table->string('currency_code', 8);
            $table->string('period', 10);
            $table->unsignedBigInteger('price_minor')->default(0);
            $table->string('status', 16)->comment('trialing | active | past_due | expired');
            $table->boolean('auto_renew')->default(true);
            $table->timestamp('started_at');
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            // A change that waits for the end of the period (a downgrade).
            $table->foreignId('pending_plan_id')->nullable()->constrained('freelance_plans')->nullOnDelete();
            $table->string('pending_period', 10)->nullable();
            $table->timestamp('grace_until')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamps();
            $table->index(['team_id', 'status']);
            $table->index(['status', 'period_end']);
        });

        Schema::create('freelance_team_subscription_invoices', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            // The foreign keys are named by hand: the generated names would pass MySQL's 64 characters.
            $table->foreignId('team_subscription_id')->constrained('freelance_team_subscriptions', 'id', 'fl_team_inv_sub_fk')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained('freelance_teams', 'id', 'fl_team_inv_team_fk')->cascadeOnDelete();
            $table->foreignId('paid_by_freelancer_id')->constrained('freelancers', 'id', 'fl_team_inv_payer_fk')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('freelance_plans', 'id', 'fl_team_inv_plan_fk');
            $table->string('period', 10);
            $table->string('kind', 12)->comment('purchase | upgrade | renewal');
            $table->unsignedBigInteger('price_minor');
            $table->unsignedBigInteger('credit_minor')->default(0)->comment('unused time of the old plan taken off');
            $table->unsignedBigInteger('amount_minor')->comment('what was charged');
            $table->string('currency_code', 8);
            $table->string('status', 10)->comment('paid | failed');
            $table->string('failure_code', 40)->nullable();
            $table->unsignedBigInteger('wallet_transaction_id')->nullable();
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index(['team_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freelance_team_subscription_invoices');
        Schema::dropIfExists('freelance_team_subscriptions');
        Schema::table('freelance_plans', function (Blueprint $table): void {
            $table->dropIndex(['audience', 'rank']);
            $table->dropColumn(['team_projects_max', 'team_members_max', 'audience']);
        });
    }
};
