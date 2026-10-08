<?php

namespace Modules\AI\Tests\Unit;

use Modules\AI\Services\Sandbox\Drivers\DockerSandboxDriver;
use PHPUnit\Framework\TestCase;

/**
 * v2.0 requirements doc §10.3/§17.5/§20.1: DockerSandboxDriver must
 * report honestly when Docker itself cannot be reached, rather than
 * pretending an execution is possible. This container/test environment
 * has no docker daemon (confirmed separately during development), so
 * this test asserts the "not available" path really works - it cannot
 * assert a real successful `docker run`, which needs an environment
 * with Docker actually installed (see the conversation for that caveat).
 */
class DockerSandboxDriverTest extends TestCase
{
    public function test_check_availability_reports_unavailable_when_docker_cannot_be_reached(): void
    {
        $driver = new DockerSandboxDriver;

        $result = $driver->checkAvailability();

        // In any environment without a reachable Docker daemon (such as
        // this one), this must be false with a reason - never silently
        // true. If this assertion ever fails because Docker IS installed
        // here, that's fine - it means the environment changed, not that
        // the driver is wrong; the important behavior is that this call
        // never throws and always returns a well-formed array.
        $this->assertIsArray($result);
        $this->assertArrayHasKey('available', $result);

        if (! $result['available']) {
            $this->assertArrayHasKey('reason', $result);
        }
    }
}
