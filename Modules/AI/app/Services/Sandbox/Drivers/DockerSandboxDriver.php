<?php

namespace Modules\AI\Services\Sandbox\Drivers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\AI\Services\Sandbox\Contracts\SandboxDriver;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Real, isolated code execution via `docker run`:
 *  - --network none: no network access at all, satisfying §17.5.
 *  - --rm: the container (and any state inside it) is destroyed after
 *    the run - nothing about this execution survives on the host.
 *  - --memory / --cpus / --pids-limit: hard resource ceilings so one
 *    execution can never starve the host.
 *  - a wall-clock timeout enforced by the PHP process itself (on top of
 *    Docker's own limits), so a hung container can't hang the request.
 *  - the code is written to a throwaway temp file mounted read-only
 *    into the container - never passed as a shell string, which would
 *    open a command-injection hole.
 *
 * If the `docker` binary isn't reachable at all, checkAvailability()
 * reports that plainly and AiSandboxRunner records status=unavailable -
 * this driver never fabricates a "ran successfully" result.
 */
class DockerSandboxDriver implements SandboxDriver
{
    public function checkAvailability(): array
    {
        try {
            $process = new Process(['docker', 'version', '--format', '{{.Server.Version}}']);
            $process->setTimeout(5);
            $process->run();

            if (! $process->isSuccessful()) {
                return ['available' => false, 'reason' => 'docker_daemon_unreachable'];
            }

            return ['available' => true];
        } catch (\Throwable $e) {
            return ['available' => false, 'reason' => 'docker_binary_not_found'];
        }
    }

    public function run(array $languageConfig, string $code): array
    {
        $startedAt = microtime(true);

        $tempDir = storage_path('app/private/ai-sandbox/'.Str::uuid());

        if (! is_dir($tempDir) && ! mkdir($tempDir, 0700, true) && ! is_dir($tempDir)) {
            return $this->failure('Could not create a sandbox working directory.', $startedAt);
        }

        $fileName = 'source.'.$languageConfig['extension'];
        $filePath = $tempDir.DIRECTORY_SEPARATOR.$fileName;

        try {
            file_put_contents($filePath, $code);
            chmod($filePath, 0400);

            $timeoutSeconds = (int) config('ai.sandbox.timeout_seconds', 10);

            $command = array_merge(
                [
                    'docker', 'run', '--rm',
                    '--network', 'none',
                    '--memory', (string) config('ai.sandbox.memory_limit', '256m'),
                    '--cpus', (string) config('ai.sandbox.cpus', '0.5'),
                    '--pids-limit', (string) config('ai.sandbox.pids_limit', 64),
                    '--user', '1000:1000',
                    '-v', $tempDir.':/sandbox:ro',
                    '-w', '/sandbox',
                    $languageConfig['image'],
                ],
                array_map(
                    fn (string $part) => $part === '{file}' ? '/sandbox/'.$fileName : $part,
                    $languageConfig['command'],
                ),
            );

            $process = new Process($command);
            $process->setTimeout($timeoutSeconds + 5);

            try {
                $process->run();
            } catch (ProcessTimedOutException) {
                return [
                    'status' => 'timeout',
                    'exit_code' => null,
                    'stdout' => $process->getOutput(),
                    'stderr' => 'Execution exceeded the sandbox time limit ('.$timeoutSeconds.'s).',
                    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                ];
            }

            return [
                'status' => $process->isSuccessful() ? 'completed' : 'failed',
                'exit_code' => $process->getExitCode(),
                'stdout' => mb_substr($process->getOutput(), 0, 10000),
                'stderr' => mb_substr($process->getErrorOutput(), 0, 10000),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ];
        } catch (\Throwable $e) {
            Log::warning('AI sandbox execution failed unexpectedly.', ['error' => $e->getMessage()]);

            return $this->failure($e->getMessage(), $startedAt);
        } finally {
            $this->cleanup($tempDir);
        }
    }

    protected function failure(string $message, float $startedAt): array
    {
        return [
            'status' => 'failed',
            'exit_code' => null,
            'stdout' => '',
            'stderr' => $message,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ];
    }

    protected function cleanup(string $tempDir): void
    {
        if (! is_dir($tempDir)) {
            return;
        }

        foreach (glob($tempDir.'/*') ?: [] as $file) {
            @chmod($file, 0600);
            @unlink($file);
        }

        @rmdir($tempDir);
    }
}
