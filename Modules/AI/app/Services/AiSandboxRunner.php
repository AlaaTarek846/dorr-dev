<?php

namespace Modules\AI\Services;

use Modules\AI\Models\AiCodeExecution;
use Modules\AI\Models\AiConversation;
use Modules\AI\Models\AiRequest;
use Modules\AI\Services\Sandbox\Contracts\SandboxDriver;
use Modules\AI\Services\Sandbox\Drivers\DockerSandboxDriver;

/**
 * v2.0 requirements doc §10.3/§17.5: the single entry point the "code"
 * domain pipeline calls to actually execute a candidate code block and
 * get back a real, persisted result - never the model's own unverified
 * claim that its code works.
 */
class AiSandboxRunner
{
    public function __construct(protected ?SandboxDriver $driver = null)
    {
        $this->driver ??= $this->resolveDriver();
    }

    public function isEnabled(): bool
    {
        return (bool) config('ai.sandbox.enabled', true);
    }

    /**
     * @return array{key: string, image: string, command: list<string>, extension: string}|null
     */
    public function languageConfig(string $language): ?array
    {
        $languages = config('ai.sandbox.languages', []);
        $key = strtolower($language);

        if (! isset($languages[$key])) {
            return null;
        }

        return array_merge(['key' => $key], $languages[$key]);
    }

    /**
     * Runs $code once, persists the real result to ai_code_executions,
     * and returns the saved record.
     */
    public function execute(
        string $language,
        string $code,
        ?AiRequest $request = null,
        ?AiConversation $conversation = null,
        int $attemptNumber = 1,
    ): AiCodeExecution {
        $languageConfig = $this->languageConfig($language);

        if (! $this->isEnabled() || ! $languageConfig) {
            return AiCodeExecution::query()->create([
                'request_id' => $request?->id,
                'conversation_id' => $conversation?->id,
                'language' => $language,
                'driver' => 'none',
                'code' => $code,
                'status' => AiCodeExecution::STATUS_UNAVAILABLE,
                'attempt_number' => $attemptNumber,
                'stderr' => $languageConfig ? 'Sandbox execution is disabled.' : "Unsupported language [{$language}] for sandbox execution.",
            ]);
        }

        $availability = $this->driver->checkAvailability();

        if (! $availability['available']) {
            return AiCodeExecution::query()->create([
                'request_id' => $request?->id,
                'conversation_id' => $conversation?->id,
                'language' => $language,
                'driver' => class_basename($this->driver),
                'code' => $code,
                'status' => AiCodeExecution::STATUS_UNAVAILABLE,
                'attempt_number' => $attemptNumber,
                'stderr' => 'Sandbox driver unavailable: '.($availability['reason'] ?? 'unknown'),
            ]);
        }

        $result = $this->driver->run($languageConfig, $code);

        return AiCodeExecution::query()->create([
            'request_id' => $request?->id,
            'conversation_id' => $conversation?->id,
            'language' => $language,
            'driver' => class_basename($this->driver),
            'code' => $code,
            'status' => $result['status'],
            'exit_code' => $result['exit_code'],
            'stdout' => $result['stdout'],
            'stderr' => $result['stderr'],
            'duration_ms' => $result['duration_ms'],
            'attempt_number' => $attemptNumber,
        ]);
    }

    protected function resolveDriver(): SandboxDriver
    {
        return match (config('ai.sandbox.driver', 'docker')) {
            'docker' => app(DockerSandboxDriver::class),
            default => app(DockerSandboxDriver::class),
        };
    }
}
