<?php

namespace Modules\AI\Console\Commands;

use Illuminate\Console\Command;
use Modules\AI\Enums\AiModelCategory;
use Modules\AI\Models\AiProvider;
use Modules\AI\Services\AiProviderModelSyncService;

/**
 * Dynamic Model Registry, section 20: the platform already carries ~103
 * models registered one-by-one by hand over the course of earlier work,
 * long before category/model_family/snapshot/alias/status existed as
 * columns. Those rows are real, live data an admin built up model by
 * model - re-syncing from OpenAI would only add NEW ids, never retro-fit
 * these registry fields onto ones already sitting in the table.
 *
 * This is the one-time (safely re-runnable) backfill: it walks every
 * EXISTING ai_provider_models row, computes category/model_family/
 * is_snapshot/release_date/canonical_model_id/is_alias/needs_review from
 * its model_key using the exact same rules AiProviderModelSyncService::sync()
 * applies to a freshly-fetched model - and touches NOTHING else. It never
 * calls any provider's API (no network, no api_key needed), never
 * creates or deletes a row, and never modifies capabilities, is_active,
 * is_default, temperature, or any other field an admin may have already
 * set by hand.
 */
class NormalizeAiModels extends Command
{
    protected $signature = 'ai:normalize-models {--dry-run : Report what would change without saving it}';

    protected $description = 'Backfill category/model_family/snapshot/alias metadata onto existing ai_provider_models rows, without touching capabilities, is_active, is_default or any other admin-set field.';

    public function handle(AiProviderModelSyncService $modelSync): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $totalUpdated = 0;

        AiProvider::query()->with('models')->get()->each(function (AiProvider $provider) use ($modelSync, $dryRun, &$totalUpdated) {
            $allModelIds = $provider->models->pluck('model_key')->all();

            $rows = [];

            foreach ($provider->models as $model) {
                $category = $modelSync->inferCategory($model->model_key);
                $family = $modelSync->parseModelFamily($model->model_key);
                $snapshot = $modelSync->parseSnapshot($model->model_key);
                $aliasOf = $modelSync->detectAlias($model->model_key, $allModelIds);

                $payload = [
                    'category' => $category,
                    'needs_review' => $category === AiModelCategory::Unknown->value,
                    'model_family' => $family,
                    'canonical_model_id' => $snapshot['canonical_model_id'] ?? $aliasOf,
                    'is_alias' => $aliasOf !== null,
                    'is_snapshot' => $snapshot['is_snapshot'],
                    'release_date' => $snapshot['release_date'],
                ];

                // status/last_seen_at are deliberately left alone here -
                // this command has no network call to tell "still offered
                // by the provider" from "gone", so it must never guess a
                // lifecycle state. A row with no status yet defaults to
                // 'active' at the database level (see the registry
                // migration); ai:sync-models is what actually keeps
                // status/last_seen_at truthful going forward.
                $rows[] = [$provider->key, $model->model_key, $category, $family, $snapshot['is_snapshot'] ? 'yes' : 'no', $aliasOf ?? '-'];

                if (! $dryRun) {
                    $model->forceFill($payload)->save();
                }

                $totalUpdated++;
            }

            if ($rows !== []) {
                $this->line("<info>{$provider->key}</info>");
                $this->table(['Provider', 'Model', 'Category', 'Family', 'Snapshot', 'Alias of'], $rows);
            }
        });

        if ($dryRun) {
            $this->comment("Dry run - {$totalUpdated} row(s) would be normalized, nothing was saved.");
        } else {
            $this->info("Normalized {$totalUpdated} existing model row(s).");
        }

        return self::SUCCESS;
    }
}
