<?php

namespace Modules\AI\Services\Sandbox\Contracts;

interface SandboxDriver
{
    /**
     * @return array{available: bool, reason?: string}
     */
    public function checkAvailability(): array;

    /**
     * Executes $code for the given language config and returns the real
     * result of that execution - never a guess or the model's own claim.
     *
     * @param  array{image: string, command: list<string>, extension: string}  $languageConfig
     * @return array{status: string, exit_code: ?int, stdout: string, stderr: string, duration_ms: int}
     */
    public function run(array $languageConfig, string $code): array;
}
