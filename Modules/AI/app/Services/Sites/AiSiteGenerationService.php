<?php

namespace Modules\AI\Services\Sites;

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

            $result = $this->callModel($target, $messages);

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
        } catch (AiSiteException $e) {
            $this->fail($version, $project, $e->reason);
        } catch (\Throwable $e) {
            report($e);
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
