<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DORR AI Marketing Director, phase C (docs/marketing_tasks.md): ad platform connections (Meta first), campaigns
 * with their state machine, every publish attempt with its Idempotency-Key, delegations (D7) and signed webhooks.
 * Tokens are stored encrypted (`credential_ref`), never in plain text and never shown or sent to the AI (POL-04/05).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_approvals', function (Blueprint $table) {
            $table->string('delegate_type', 40)->nullable()->after('actor_id')->comment('delegation: who may approve spend under it');
            $table->unsignedBigInteger('delegate_id')->nullable()->after('delegate_type');
            $table->unsignedBigInteger('delegation_id')->nullable()->after('delegate_id')->comment('campaign approval given under a delegation');
        });

        Schema::create('marketing_integrations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('project_id')->constrained('marketing_projects')->cascadeOnDelete();
            $table->string('provider', 20)->comment('meta (google, tiktok, snapchat later)');
            $table->string('external_account_ref', 64)->nullable()->comment('ad account id, e.g. act_123');
            $table->string('external_account_name', 160)->nullable();
            $table->string('account_currency', 8)->nullable();
            $table->json('scopes')->nullable();
            $table->text('credential_ref')->nullable()->comment('encrypted access token');
            $table->timestamp('token_expires_at')->nullable();
            $table->string('status', 20)->comment('PENDING | CONNECTED | EXPIRED | REVOKED | ERROR');
            $table->string('oauth_state_hash', 64)->nullable();
            $table->timestamp('oauth_state_expires_at')->nullable();
            $table->json('available_accounts')->nullable()->comment('ad accounts to choose from after OAuth');
            $table->boolean('sandbox')->default(false);
            $table->string('last_error', 300)->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->string('connected_by_type', 40)->nullable();
            $table->unsignedBigInteger('connected_by_id')->nullable();
            $table->timestamps();
            $table->index(['project_id', 'provider']);
            $table->index('oauth_state_hash');
        });

        Schema::create('marketing_campaigns', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('project_id')->constrained('marketing_projects')->cascadeOnDelete();
            $table->foreignId('integration_id')->nullable()->constrained('marketing_integrations')->nullOnDelete();
            $table->foreignId('content_item_id')->nullable()->constrained('marketing_content_items')->nullOnDelete();
            $table->string('provider', 20);
            $table->string('name', 160);
            $table->string('objective', 20)->comment('awareness | traffic | engagement | leads | sales');
            $table->string('status', 20)->comment('DRAFT | VALIDATED | AWAITING_APPROVAL | APPROVED | SUBMITTING | PUBLISHED | REJECTED | FAILED | UNKNOWN');
            $table->unsignedBigInteger('daily_budget_minor');
            $table->unsignedBigInteger('total_budget_minor');
            $table->string('currency', 8);
            $table->timestamp('start_at');
            $table->timestamp('end_at');
            $table->json('targeting')->comment('{countries, age_min, age_max}');
            $table->unsignedInteger('version')->default(1);
            $table->string('payload_hash', 64)->nullable();
            $table->json('validation')->nullable()->comment('[{code, detail}] of the last check');
            $table->string('platform_ref', 64)->nullable();
            $table->string('platform_status', 30)->nullable();
            $table->string('last_error', 300)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('metrics_synced_at')->nullable();
            $table->string('created_by_type', 40)->nullable();
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['project_id', 'status']);
            $table->index('platform_ref');
        });

        Schema::create('marketing_campaign_actions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('project_id')->constrained('marketing_projects')->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained('marketing_campaigns')->cascadeOnDelete();
            $table->string('action_type', 20)->comment('publish | reconcile');
            $table->string('idempotency_key', 80);
            $table->string('payload_hash', 64);
            $table->unsignedInteger('campaign_version');
            $table->foreignId('approval_id')->nullable()->constrained('marketing_approvals')->nullOnDelete();
            $table->string('marker', 40)->comment('written into the platform object so an unknown result can be found again');
            $table->json('request_json')->nullable();
            $table->json('response_json')->nullable();
            $table->string('status', 20)->comment('SUBMITTING | SUCCEEDED | FAILED | UNKNOWN');
            $table->string('external_ref', 64)->nullable();
            $table->string('error_code', 60)->nullable();
            $table->string('actor_type', 40)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'idempotency_key', 'action_type']);
        });

        Schema::create('marketing_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20);
            $table->string('event_hash', 64)->unique()->comment('a repeated delivery is ignored (POL-15)');
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_webhook_events');
        Schema::dropIfExists('marketing_campaign_actions');
        Schema::dropIfExists('marketing_campaigns');
        Schema::dropIfExists('marketing_integrations');
        Schema::table('marketing_approvals', function (Blueprint $table) {
            $table->dropColumn(['delegate_type', 'delegate_id', 'delegation_id']);
        });
    }
};
