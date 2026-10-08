<?php

namespace Modules\AI\Console\Commands;

use Illuminate\Console\Command;
use Modules\AI\Models\AiModelSyncLog;
use Modules\AI\Models\AiProvider;
use Modules\AI\Services\AiGateway;
use Modules\AI\Services\AiProviderModelSyncService;

/**
 * Dynamic Model Registry, section 12/17: the admin-triggered
 * "test connection" click already registers models via
 * AiProviderModelSyncService (see AiProviderService::testConnection()),
 * but that only ever happens when a human clicks the button. This is the
 * same real sync - same AiGateway::test() call to fetch the provider's
 * live model list, same AiProviderModelSyncService::sync() to reconcile
 * it - runnable unattended: on a schedule (see AIServiceProvider's
 * configureSchedules(), interval from config('ai.model_sync.interval_hours'))
 * or on demand ({provider?} argument, or none for every usable provider).
 *
 * Deliberately calls the OpenAI/etc "/models" endpoint ONLY here, in the
 * admin "test connection" action, and nowhere else - this command is one
 * of the exactly two places in the whole codebase that ever does, by
 * design (never on a page load, never per chat message: the registry
 * table is the cache every other code path actually reads from).
 *
 * Every run is recorded as an ai_model_sync_logs row, success or failure,
 * so "did the last scheduled sync actually work" is answerable without
 * digging through application logs.
 */
class SyncAiModels extends Command
{
    protected $signature = 'ai:sync-models {provider? : A single provider key (e.g. openai) - all usable providers if omitted}';

    protected $description = 'Fetch each AI provider\'s live model list and reconcile it into the ai_provider_models registry (create/update/deprecate/reactivate - never delete).';

    public function handle(AiGateway $gateway, AiProviderModelSyncService $modelSync): int
    {
        $providerKey = $this->argument('provider');

        if ($providerKey !== null) {
            $provider = AiProvider::query()->where('key', $providerKey)->first();

            if (! $provider) {
                $this->error("No AI provider is registered with key \"{$providerKey}\".");

                return self::FAILURE;
            }

            $providers = collect([$provider]);
        } else {
            $providers = AiProvider::query()->get()->filter->isUsableForChat()->values();
        }

        if ($providers->isEmpty()) {
            $this->info('No enabled, API-key-configured AI provider to sync.');

            return self::SUCCESS;
        }

        $rows = [];
        $anyFailed = false;

        foreach ($providers as $provider) {
            $startedAt = now();

            try {
                $result = $gateway->test($provider);
            } catch (\Throwable $e) {
                report($e);

                AiModelSyncLog::query()->create([
                    'provider_id' => $provider->id,
                    'started_at' => $startedAt,
                    'completed_at' => now(),
                    'status' => AiModelSyncLog::STATUS_FAILED,
                    'error_message' => $e->getMessage(),
                ]);

                $rows[] = [$provider->key, 'failed', $e->getMessage(), '-', '-', '-', '-', '-'];
                $anyFailed = true;

                continue;
            }

            if (! $result['success']) {
                AiModelSyncLog::query()->create([
                    'provider_id' => $provider->id,
                    'started_at' => $startedAt,
                    'completed_at' => now(),
                    'status' => AiModelSyncLog::STATUS_FAILED,
                    'error_message' => $result['message'] ?? 'Connection test failed.',
                ]);

                $rows[] = [$provider->key, 'failed', $result['message'] ?? 'Connection test failed.', '-', '-', '-', '-', '-'];
                $anyFailed = true;

                continue;
            }

            $models = $result['models'] ?? [];
            $sync = $models !== []
                ? $modelSync->sync($provider, $models)
                : ['created' => 0, 'skipped' => 0, 'updated' => 0, 'deprecated' => 0, 'reactivated' => 0, 'found' => 0];

            if ($models !== []) {
                $provider->update([
                    'available_models' => $models,
                    'available_models_synced_at' => now(),
                ]);
            }

            AiModelSyncLog::query()->create([
                'provider_id' => $provider->id,
                'started_at' => $startedAt,
                'completed_at' => now(),
                'models_found' => $sync['found'],
                'models_created' => $sync['created'],
                'models_updated' => $sync['updated'],
                'models_deprecated' => $sync['deprecated'],
                'models_reactivated' => $sync['reactivated'],
                'status' => AiModelSyncLog::STATUS_SUCCEEDED,
            ]);

            $rows[] = [
                $provider->key, 'succeeded', $sync['found'], $sync['created'],
                $sync['updated'], $sync['deprecated'], $sync['reactivated'], $sync['skipped'],
            ];
        }

        $this->table(
            ['Provider', 'Status', 'Found/Error', 'Created', 'Updated', 'Deprecated', 'Reactivated', 'Skipped'],
            $rows,
        );

        return $anyFailed ? self::FAILURE : self::SUCCESS;
    }
}
