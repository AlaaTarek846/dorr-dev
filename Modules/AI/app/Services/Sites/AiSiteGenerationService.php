<?php

namespace Modules\AI\Services\Sites;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\AI\Exceptions\AiSiteException;
use Modules\AI\Models\AiProvider;
use Modules\AI\Models\AiSiteProject;
use Modules\AI\Models\AiSiteVersion;
use Modules\AI\Repositories\AiProviderRepository;
use Modules\AI\Services\AiGateway;
use Modules\AI\Services\AiModelResolver;

/**
 * Runs one queued version of a site: asks the model for the files, checks
 * them, writes them and moves the project's current version forward. Any
 * failure leaves the previous working version in place.
 */
class AiSiteGenerationService
{
    public function __construct(
        protected AiGateway $gateway,
        protected AiProviderRepository $providers,
        protected AiModelResolver $modelResolver,
        protected AiSitePromptBuilder $prompts,
        protected AiSiteResponseParser $parser,
        protected AiSiteFileGuard $guard,
        protected AiSiteStorage $storage,
        protected AiSiteEntitlementService $entitlements,
    ) {}

    public function run(AiSiteVersion $version): void
    {
        if ($version->status !== AiSiteVersion::STATUS_PENDING) {
            return;
        }

        $project = $version->project()->withTrashed()->first();

        if ($project === null || $project->trashed() || $project->isDisabled()) {
            $this->fail($version, $project, 'project_unavailable');

            return;
        }

        $version->forceFill(['status' => AiSiteVersion::STATUS_PROCESSING])->save();
        $project->forceFill(['status' => AiSiteProject::STATUS_GENERATING])->save();

        try {
            $base = $version->kind === AiSiteVersion::KIND_EDIT && $project->currentVersion !== null
                ? $this->storage->versionFiles($project->currentVersion)
                : [];

            $target = $this->resolveTarget();

            $messages = $version->kind === AiSiteVersion::KIND_EDIT
                ? $this->prompts->forEdit((array) $project->brief, $base, (string) $version->instruction)
                : $this->prompts->forGenerate((array) $project->brief);

            $startedAt = microtime(true);
            Log::info('ai.site_generation_started', [
                'version_id' => $version->id, 'number' => $version->number, 'kind' => $version->kind,
                'model' => $target['model_key'], 'prompt_bytes' => strlen((string) json_encode($messages)),
            ]);
            $result = $this->callModel($target, $messages);

            // A model that was retired or renamed at the provider ("gpt-5-codex has been deprecated") would
            // otherwise fail every build while other registered models work: try the next coding-capable one.
            $skip = [];

            while (! ($result['success'] ?? false) && $this->modelIsUnavailable((string) ($result['message'] ?? '')) && count($skip) < 3) {
                $skip[] = $target['model_key'];
                Log::warning('ai.site_model_unavailable', ['model' => $target['model_key'], 'message' => Str::limit((string) ($result['message'] ?? ''), 300)]);

                $alternative = $this->resolveAlternative($skip);

                if ($alternative === null) {
                    break;
                }

                $target = $alternative;
                $result = $this->callModel($target, $messages);
            }

            Log::info('ai.site_generation_model_replied', [
                'version_id' => $version->id, 'success' => (bool) ($result['success'] ?? false),
                'seconds' => round(microtime(true) - $startedAt, 1), 'bytes' => strlen((string) ($result['content'] ?? '')),
                'message' => Str::limit((string) ($result['message'] ?? ''), 300),
            ]);

            if (! $result['success'] || blank($result['content'] ?? null)) {
                $version->forceFill(['error_message' => Str::limit((string) ($result['message'] ?? ''), 500)])->save();

                throw new AiSiteException('provider_failed', 502);
            }

            $parsed = $this->parser->parse((string) $result['content']);
            $final = $base;

            foreach ($parsed['files'] as $path => $content) {
                $final[$this->guard->cleanPath($path)] = $content;
            }

            foreach ($parsed['deletes'] as $path) {
                unset($final[$this->guard->cleanPath($path)]);
            }

            $this->guard->assertValid($final);

            $written = $this->storage->writeVersion($version, $final);

            $version->forceFill([
                'status' => AiSiteVersion::STATUS_COMPLETED,
                'files' => $written['manifest'],
                'total_bytes' => $written['total'],
                'provider_id' => $target['provider']->id,
                'model_key' => $target['model_key'],
                'error_message' => null,
                'completed_at' => now(),
            ])->save();

            $project->forceFill([
                'status' => AiSiteProject::STATUS_READY,
                'current_version_id' => $version->id,
                'last_error' => null,
            ])->save();
            Log::info('ai.site_generation_completed', ['version_id' => $version->id, 'files' => count($written['manifest']), 'bytes' => $written['total']]);
        } catch (AiSiteException $e) {
            // Failures used to be silent (only a short code reached the customer), which made a slow or
            // rejected model call impossible to diagnose from the log.
            Log::warning('ai.site_generation_failed', [
                'version_id' => $version->id,
                'kind' => $version->kind,
                'reason' => $e->reason,
                'detail' => $version->fresh()?->error_message,
                'model' => $target['model_key'] ?? null,
                'seconds' => isset($startedAt) ? round(microtime(true) - $startedAt, 1) : null,
            ]);
            $this->fail($version, $project, $e->reason);
        } catch (\Throwable $e) {
            report($e);
            Log::warning('ai.site_generation_error', ['version_id' => $version->id, 'kind' => $version->kind, 'error' => $e->getMessage()]);
            $version->forceFill(['error_message' => Str::limit($e->getMessage(), 500)])->save();
            $this->fail($version, $project, 'generation_failed');
        }
    }

    /** Marks a version failed, gives back any purchased allowance and keeps the last good site live. */
    public function fail(AiSiteVersion $version, ?AiSiteProject $project, string $reason): void
    {
        $wasCounted = $version->counted;

        $version->forceFill([
            'status' => AiSiteVersion::STATUS_FAILED,
            'counted' => false,
            'completed_at' => now(),
            'error_message' => $version->error_message ?: $reason,
        ])->save();

        if ($project === null) {
            return;
        }

        $project->forceFill([
            'status' => $project->current_version_id ? AiSiteProject::STATUS_READY : AiSiteProject::STATUS_FAILED,
            'last_error' => $reason,
        ])->save();

        if ($wasCounted && $project->access_type === AiSiteProject::ACCESS_PURCHASE && $project->purchase !== null) {
            $this->entitlements->refundPurchase($project->purchase);
        }
    }

    /** True when the provider says the model itself is gone/retired/unknown (not a transient or content error). */
    protected function modelIsUnavailable(string $message): bool
    {
        return preg_match('/deprecat|decommission|no longer (available|supported)|does not exist|model_not_found|not found|unknown model|invalid model|retired/i', $message) === 1;
    }

    /**
     * Another usable model (coding-capable first, then any chat model) that is not in $skip.
     *
     * @param  list<string>  $skip  model keys already tried
     * @return array{provider: AiProvider, model_key: string}|null
     */
    protected function resolveAlternative(array $skip): ?array
    {
        foreach ([['coding'], []] as $required) {
            foreach ($this->providers->all() as $provider) {
                if (! $provider->isUsableForChat()) {
                    continue;
                }

                $model = $provider->activeModels()->get()->first(
                    fn ($m) => ! in_array($m->model_key, $skip, true) && ($required === [] ? $m->hasAllCapabilities(['chat']) : $m->hasAllCapabilities($required)),
                );

                if ($model === null) {
                    continue;
                }

                $clone = tap(clone $provider, function (AiProvider $p) use ($model) {
                    $p->model = $model->model_key;
                    $p->max_tokens = (int) config('ai.sites.max_output_tokens', 16000);
                });

                return ['provider' => $clone, 'model_key' => $model->model_key];
            }
        }

        return null;
    }

    /**
     * Best coding-capable model that is connected, else any usable one.
     *
     * @return array{provider: AiProvider, model_key: string}
     */
    protected function resolveTarget(): array
    {
        $match = $this->modelResolver->resolve($this->providers, ['coding']) ?? $this->modelResolver->resolve($this->providers);

        if ($match === null) {
            throw new AiSiteException('no_model', 503);
        }

        $provider = tap(clone $match['provider'], function (AiProvider $p) use ($match) {
            $p->model = $match['model']->model_key;
            $p->max_tokens = (int) config('ai.sites.max_output_tokens', 16000);
        });

        return ['provider' => $provider, 'model_key' => $match['model']->model_key];
    }

    /**
     * A whole site takes far longer than a chat reply, so the provider timeout
     * is raised for this one call only.
     *
     * @param  array{provider: AiProvider, model_key: string}  $target
     * @param  list<array{role: string, content: string}>  $messages
     * @return array{success: bool, message: string, content: ?string}
     */
    protected function callModel(array $target, array $messages): array
    {
        $previous = config('ai.request_timeout');
        config(['ai.request_timeout' => (int) config('ai.sites.request_timeout', 600)]);

        try {
            return $this->gateway->chat($target['provider'], $messages);
        } finally {
            config(['ai.request_timeout' => $previous]);
        }
    }
}
