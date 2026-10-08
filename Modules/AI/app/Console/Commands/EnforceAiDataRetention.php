<?php

namespace Modules\AI\Console\Commands;

use Illuminate\Console\Command;
use Modules\AI\Models\AiDataPolicy;
use Modules\AI\Models\AiFailover;
use Modules\AI\Models\AiProviderLog;
use Modules\AI\Models\AiRequest;
use Modules\AI\Models\AiSafetyEvent;
use Modules\AI\Models\AiSecurityEvent;

/**
 * v2.0 requirements doc, 17.3: apply retention and authorized deletion.
 * Purges only the AI module's operational/audit tables that are older
 * than the retention window configured on the active
 * ai_data_policies row for personal-classified data - never
 * ai_conversations/ai_messages, which are product data the customer or
 * provider owns and controls themselves (see AiChatController's own
 * export/erase endpoints for that).
 *
 * Deleting ai_requests cascades (FK cascadeOnDelete) to ai_responses,
 * ai_usage, ai_verifications and ai_request_citations automatically.
 */
class EnforceAiDataRetention extends Command
{
    protected $signature = 'ai:enforce-retention {--dry-run : Report what would be deleted without deleting it}';

    protected $description = 'Purge AI audit/operational records older than the configured data retention policy.';

    public function handle(): int
    {
        $policy = AiDataPolicy::query()
            ->where('data_classification', AiDataPolicy::CLASSIFICATION_PERSONAL)
            ->where('is_active', true)
            ->orderByDesc('id')
            ->first();

        if (! $policy || ! $policy->retention_days) {
            $this->info('No active retention policy configured for personal-classified data - nothing to do.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($policy->retention_days);
        $dryRun = (bool) $this->option('dry-run');

        $this->info("Retention cutoff: records before {$cutoff->toDateTimeString()} ({$policy->retention_days} days, policy #{$policy->id}).");

        $requestsQuery = AiRequest::query()->where('created_at', '<', $cutoff);
        $requestsCount = $requestsQuery->count();

        $providerLogsQuery = AiProviderLog::query()->where('created_at', '<', $cutoff);
        $providerLogsCount = $providerLogsQuery->count();

        $safetyEventsQuery = AiSafetyEvent::query()->where('created_at', '<', $cutoff);
        $safetyEventsCount = $safetyEventsQuery->count();

        $securityEventsQuery = AiSecurityEvent::query()->where('created_at', '<', $cutoff);
        $securityEventsCount = $securityEventsQuery->count();

        $failoversQuery = AiFailover::query()->where('created_at', '<', $cutoff);
        $failoversCount = $failoversQuery->count();

        $this->table(['Table', 'Rows to purge'], [
            ['ai_requests (+ responses/usage/verifications/citations)', $requestsCount],
            ['ai_provider_logs', $providerLogsCount],
            ['ai_safety_events', $safetyEventsCount],
            ['ai_security_events', $securityEventsCount],
            ['ai_failovers', $failoversCount],
        ]);

        if ($dryRun) {
            $this->comment('Dry run - nothing was deleted.');

            return self::SUCCESS;
        }

        $requestsQuery->delete();
        $providerLogsQuery->delete();
        $safetyEventsQuery->delete();
        $securityEventsQuery->delete();
        $failoversQuery->delete();

        $this->info('Retention enforcement completed.');

        return self::SUCCESS;
    }
}
