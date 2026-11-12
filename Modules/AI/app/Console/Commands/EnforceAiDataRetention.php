<?php

namespace Modules\AI\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\AI\Models\AiAuditEvent;
use Modules\AI\Models\AiCodeExecution;
use Modules\AI\Models\AiDataPolicy;
use Modules\AI\Models\AiFailover;
use Modules\AI\Models\AiProviderHealth;
use Modules\AI\Models\AiProviderLog;
use Modules\AI\Models\AiRequest;
use Modules\AI\Models\AiSafetyEvent;
use Modules\AI\Models\AiSafetyScan;
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
 *
 * ai_code_executions and ai_audit_events can have their own window
 * (config ai.retention.*_days); empty = the policy's retention_days.
 * ai_provider_health keeps the latest row per provider (it is the circuit
 * breaker's current state), whatever its age.
 */
class EnforceAiDataRetention extends Command
{
    protected $signature = 'ai:enforce-retention {--dry-run : Report what would be deleted without deleting it}';

    protected $description = 'Purge AI audit/operational records older than the configured data retention policy.';

    public function handle(): int
    {
        // Switched off or in the trash = not read (AiDataPolicy::activePersonal).
        $policy = AiDataPolicy::activePersonal();

        if (! $policy || ! $policy->retention_days) {
            $this->info('No active retention policy configured for personal-classified data - nothing to do.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($policy->retention_days);
        $dryRun = (bool) $this->option('dry-run');

        $codeCutoff = $this->cutoffFor('code_executions_days', $policy->retention_days);
        $auditCutoff = $this->cutoffFor('audit_events_days', $policy->retention_days);

        $this->info("Retention cutoff: records before {$cutoff->toDateTimeString()} ({$policy->retention_days} days, policy #{$policy->id}).");

        // The newest health row per provider/check type is the breaker's live state - never purged.
        $latestHealthIds = AiProviderHealth::query()
            ->toBase()
            ->selectRaw('MAX(id) as latest_id')
            ->groupBy('provider_id', 'check_type')
            ->pluck('latest_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        /** @var array<string, Builder> $queries */
        $queries = [
            'ai_requests (+ responses/usage/verifications/citations)' => AiRequest::query()->where('created_at', '<', $cutoff),
            'ai_provider_logs' => AiProviderLog::query()->where('created_at', '<', $cutoff),
            'ai_safety_events' => AiSafetyEvent::query()->where('created_at', '<', $cutoff),
            'ai_safety_scans' => AiSafetyScan::query()->where('created_at', '<', $cutoff),
            'ai_security_events' => AiSecurityEvent::query()->where('created_at', '<', $cutoff),
            'ai_failovers' => AiFailover::query()->where('created_at', '<', $cutoff),
            'ai_provider_health (latest row per provider kept)' => AiProviderHealth::query()
                ->where('created_at', '<', $cutoff)
                ->whereNotIn('id', $latestHealthIds ?: [0]),
            'ai_code_executions' => AiCodeExecution::query()->where('created_at', '<', $codeCutoff),
            // Query-builder delete: the model blocks delete() (append-only); retention is the authorized purge.
            'ai_audit_events' => AiAuditEvent::query()->where('created_at', '<', $auditCutoff),
        ];

        $this->table(['Table', 'Rows to purge'], collect($queries)
            ->map(fn (Builder $query, string $table) => [$table, $query->count()])
            ->values()
            ->all());

        if ($dryRun) {
            $this->comment('Dry run - nothing was deleted.');

            return self::SUCCESS;
        }

        foreach ($queries as $query) {
            $query->toBase()->delete();
        }

        $this->info('Retention enforcement completed.');

        return self::SUCCESS;
    }

    protected function cutoffFor(string $key, int $policyDays): \Illuminate\Support\Carbon
    {
        $days = (int) config("ai.retention.{$key}");

        return now()->subDays($days > 0 ? $days : $policyDays);
    }
}
