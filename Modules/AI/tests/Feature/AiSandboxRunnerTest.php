<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AI\Models\AiCodeExecution;
use Modules\AI\Services\AiSandboxRunner;
use Modules\AI\Services\Sandbox\Contracts\SandboxDriver;
use Tests\TestCase;

/**
 * v2.0 requirements doc §10.3/§17.5/§20.1: AiSandboxRunner must always
 * persist a real, honest ai_code_executions row - including when the
 * driver reports itself unavailable, or the language isn't supported -
 * and must never fabricate a "completed" status it didn't get from the
 * driver. A fake SandboxDriver stands in for Docker here, since this
 * test environment has no docker daemon to actually call; that's real
 * infrastructure this environment can't reach, not something a unit
 * test can substitute for - see the chat conversation for how the
 * driver itself (DockerSandboxDriver) was verified separately.
 */
class AiSandboxRunnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_execute_persists_a_completed_result_from_the_driver(): void
    {
        $driver = new class implements SandboxDriver
        {
            public function checkAvailability(): array
            {
                return ['available' => true];
            }

            public function run(array $languageConfig, string $code): array
            {
                return [
                    'status' => 'completed',
                    'exit_code' => 0,
                    'stdout' => 'hello',
                    'stderr' => '',
                    'duration_ms' => 42,
                ];
            }
        };

        $runner = new AiSandboxRunner($driver);

        $execution = $runner->execute('php', "<?php\necho 'hello';");

        $this->assertInstanceOf(AiCodeExecution::class, $execution);
        $this->assertTrue($execution->exists);
        $this->assertSame(AiCodeExecution::STATUS_COMPLETED, $execution->status);
        $this->assertSame(0, $execution->exit_code);
        $this->assertTrue($execution->isSuccessful());
        $this->assertDatabaseHas('ai_code_executions', ['id' => $execution->id, 'status' => 'completed']);
    }

    public function test_execute_persists_a_failed_result_with_real_stderr(): void
    {
        $driver = new class implements SandboxDriver
        {
            public function checkAvailability(): array
            {
                return ['available' => true];
            }

            public function run(array $languageConfig, string $code): array
            {
                return [
                    'status' => 'failed',
                    'exit_code' => 1,
                    'stdout' => '',
                    'stderr' => 'ParseError: syntax error, unexpected token',
                    'duration_ms' => 10,
                ];
            }
        };

        $runner = new AiSandboxRunner($driver);

        $execution = $runner->execute('php', '<?php echo ;');

        $this->assertFalse($execution->isSuccessful());
        $this->assertSame('ParseError: syntax error, unexpected token', $execution->stderr);
    }

    public function test_execute_reports_unavailable_when_the_driver_cannot_be_reached(): void
    {
        $driver = new class implements SandboxDriver
        {
            public function checkAvailability(): array
            {
                return ['available' => false, 'reason' => 'docker_binary_not_found'];
            }

            public function run(array $languageConfig, string $code): array
            {
                throw new \RuntimeException('run() must not be called when unavailable');
            }
        };

        $runner = new AiSandboxRunner($driver);

        $execution = $runner->execute('php', '<?php echo 1;');

        $this->assertSame(AiCodeExecution::STATUS_UNAVAILABLE, $execution->status);
        $this->assertStringContainsString('docker_binary_not_found', $execution->stderr);
    }

    public function test_execute_reports_unavailable_for_an_unsupported_language_without_calling_the_driver(): void
    {
        $driver = new class implements SandboxDriver
        {
            public function checkAvailability(): array
            {
                throw new \RuntimeException('checkAvailability() must not be called for an unsupported language');
            }

            public function run(array $languageConfig, string $code): array
            {
                throw new \RuntimeException('run() must not be called for an unsupported language');
            }
        };

        $runner = new AiSandboxRunner($driver);

        $execution = $runner->execute('ruby', "puts 'hi'");

        $this->assertSame(AiCodeExecution::STATUS_UNAVAILABLE, $execution->status);
    }

    public function test_execute_respects_the_ai_sandbox_enabled_config_flag(): void
    {
        config(['ai.sandbox.enabled' => false]);

        $driver = new class implements SandboxDriver
        {
            public function checkAvailability(): array
            {
                throw new \RuntimeException('must not be called when sandbox is disabled');
            }

            public function run(array $languageConfig, string $code): array
            {
                throw new \RuntimeException('must not be called when sandbox is disabled');
            }
        };

        $runner = new AiSandboxRunner($driver);

        $execution = $runner->execute('php', '<?php echo 1;');

        $this->assertSame(AiCodeExecution::STATUS_UNAVAILABLE, $execution->status);
    }
}
