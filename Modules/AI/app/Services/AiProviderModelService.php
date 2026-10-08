<?php

namespace Modules\AI\Services;

use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\AI\Http\Resources\AiProviderModelResource;
use Modules\AI\Models\AiModelSyncLog;
use Modules\AI\Models\AiProvider;
use Modules\AI\Repositories\AiProviderRepository;

/**
 * Admin management of the models registered under a single provider
 * (ai_provider_models) - what lets "connect to OpenAI" mean "connect to
 * every model I chose from OpenAI", each independently activatable and
 * tagged with what it's actually good at, instead of the provider only
 * ever having one single $model column.
 */
class AiProviderModelService
{
    public function __construct(
        protected AiProviderRepository $providers,
        protected AiModelCapabilityClassifier $classifier,
        protected AiGateway $gateway,
        protected AiProviderModelSyncService $modelSync,
    ) {}

    public function list(string $providerKey): JsonResponse
    {
        $provider = $this->providers->findByKey($providerKey);

        return ApiResponse::success(
            AiProviderModelResource::collection($provider->models),
            __('api.retrieved'),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function store(string $providerKey, array $data): JsonResponse
    {
        $provider = $this->providers->findByKey($providerKey);

        $this->assertUniqueModelKey($provider, $data['model_key'], null);

        $model = DB::transaction(function () use ($provider, $data) {
            if ($data['is_default'] ?? false) {
                $provider->models()->update(['is_default' => false]);
            }

            return $provider->models()->create([
                'model_key' => $data['model_key'],
                'display_name' => $data['display_name'] ?? null,
                'capabilities' => array_values(array_unique($data['capabilities'])),
                'temperature' => $data['temperature'] ?? null,
                'max_tokens' => $data['max_tokens'] ?? null,
                'is_default' => (bool) ($data['is_default'] ?? false),
                'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
                'sort_order' => $data['sort_order'] ?? ($provider->models()->max('sort_order') + 1),
            ]);
        });

        return ApiResponse::created(new AiProviderModelResource($model), __('api.created'));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(string $providerKey, int $modelId, array $data): JsonResponse
    {
        $provider = $this->providers->findByKey($providerKey);
        $model = $provider->models()->findOrFail($modelId);

        if (array_key_exists('model_key', $data)) {
            $this->assertUniqueModelKey($provider, $data['model_key'], $model->id);
        }

        DB::transaction(function () use ($provider, $model, $data) {
            if (($data['is_default'] ?? false) === true) {
                $provider->models()->where('id', '!=', $model->id)->update(['is_default' => false]);
            }

            if (array_key_exists('capabilities', $data)) {
                $data['capabilities'] = array_values(array_unique($data['capabilities']));
            }

            $model->update($data);
        });

        return ApiResponse::success(new AiProviderModelResource($model->refresh()), __('api.updated'));
    }

    public function destroy(string $providerKey, int $modelId): JsonResponse
    {
        $provider = $this->providers->findByKey($providerKey);
        $model = $provider->models()->findOrFail($modelId);
        $model->delete();

        return ApiResponse::success(null, __('api.deleted'));
    }

    /**
     * Admin-triggered "reclassify with AI" action (see
     * AiModelCapabilityClassifier docblock for why this exists as a
     * second AI call rather than a smarter regex): re-runs capability
     * classification for every currently active model registered under
     * this provider and overwrites their capabilities with the result.
     * A model the admin has deactivated is left alone - deactivating is
     * already the documented way to take a model out of consideration,
     * reclassifying it would just put it back in the running silently.
     */
    public function reclassify(string $providerKey): JsonResponse
    {
        $provider = $this->providers->findByKey($providerKey);
        $models = $provider->models()->where('is_active', true)->get();

        if ($models->isEmpty()) {
            return ApiResponse::error(__('ai.classification_no_models'), 422);
        }

        $result = $this->classifier->classify($provider, $models->pluck('model_key')->all());

        if (! $result['success']) {
            return ApiResponse::error($result['message'] ?? __('ai.classification_failed'), 502);
        }

        $updated = 0;

        DB::transaction(function () use ($models, $result, &$updated) {
            foreach ($models as $model) {
                $capabilities = $result['capabilities'][$model->model_key] ?? null;

                if ($capabilities === null) {
                    continue;
                }

                $model->update(['capabilities' => $capabilities]);
                $updated++;
            }
        });

        return ApiResponse::success(
            AiProviderModelResource::collection($provider->refresh()->models),
            __('ai.classification_updated', ['count' => $updated]),
        );
    }

    /**
     * Admin-triggered "Sync Models" button (Dynamic Model Registry,
     * section 12): the exact same real sync as a successful "test
     * connection" click (AiProviderService::testConnection()) or the
     * scheduled/CLI `ai:sync-models` command - fetches the provider's
     * live model list and reconciles it (create/update/deprecate/
     * reactivate, never delete) via AiProviderModelSyncService::sync().
     * Recorded as an ai_model_sync_logs row exactly like the other two
     * trigger points, so every sync - however it was started - shows up
     * in the same history.
     */
    public function sync(string $providerKey): JsonResponse
    {
        $provider = $this->providers->findByKey($providerKey);
        $startedAt = now();

        $result = $this->gateway->test($provider);

        if (! $result['success']) {
            AiModelSyncLog::query()->create([
                'provider_id' => $provider->id,
                'started_at' => $startedAt,
                'completed_at' => now(),
                'status' => AiModelSyncLog::STATUS_FAILED,
                'error_message' => $result['message'] ?? __('ai.test_failed'),
            ]);

            return ApiResponse::error($result['message'] ?? __('ai.test_failed'), 502);
        }

        $models = $result['models'] ?? [];
        $sync = $models !== []
            ? $this->modelSync->sync($provider, $models)
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

        return ApiResponse::success(
            [
                'summary' => $sync,
                'models' => AiProviderModelResource::collection($provider->refresh()->models),
            ],
            __('ai.models_synced', ['count' => $sync['created'] + $sync['updated']]),
        );
    }

    /**
     * Admin-triggered "recalculate classification" button - deliberately
     * separate from sync() above: makes NO call to the provider's API at
     * all, so it works even when the API key is stale/rate-limited, and
     * it is the only action that can ever correct a category value that
     * is already set but wrong (sync() only ever backfills a NULL
     * category, to protect one an admin corrected by hand - see
     * AiProviderModelSyncService::normalize()'s own docblock for the
     * full reasoning). Exists for exactly the real case a provider's
     * models can end up mis-categorized under an older, less accurate
     * ruleset and stay that way forever otherwise.
     */
    public function normalize(string $providerKey): JsonResponse
    {
        $provider = $this->providers->findByKey($providerKey);

        $result = $this->modelSync->normalize($provider);

        return ApiResponse::success(
            [
                'summary' => $result,
                'models' => AiProviderModelResource::collection($provider->refresh()->models),
            ],
            __('ai.models_normalized', ['count' => $result['recategorized']]),
        );
    }

    protected function assertUniqueModelKey(AiProvider $provider, string $modelKey, ?int $ignoreId): void
    {
        $exists = $provider->models()
            ->where('model_key', $modelKey)
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'model_key' => [__('ai.model_key_already_registered')],
            ]);
        }
    }
}
