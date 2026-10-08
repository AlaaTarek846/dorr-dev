<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\Admin;
use Modules\AI\Services\AiChatUsageGuard;
use Modules\AI\Services\AiToolRegistry;
use Modules\AI\Services\Tools\AiUsageStatusTool;
use Tests\TestCase;

/**
 * AiUsageStatusTool must give the exact same answer AiChatUsageGuard
 * itself gives (it wraps the same evaluate() call) - a user asking the
 * assistant about their own usage must never hear something that
 * disagrees with what actually gates their next message.
 */
class AiToolRegistryTest extends TestCase
{
    use RefreshDatabase;

    protected function makeOwner(): Admin
    {
        return Admin::query()->create([
            'name' => 'Test Admin',
            'email' => 'tool-owner-'.uniqid().'@example.test',
            'password' => 'password',
            'status' => true,
        ]);
    }

    public function test_the_usage_status_tool_is_registered_under_its_own_name(): void
    {
        $tool = app(AiToolRegistry::class)->find('usage_status');

        $this->assertInstanceOf(AiUsageStatusTool::class, $tool);
    }

    public function test_a_missing_tool_name_resolves_to_null(): void
    {
        $this->assertNull(app(AiToolRegistry::class)->find('not_a_real_tool'));
    }

    public function test_executing_the_usage_status_tool_matches_the_real_usage_guard(): void
    {
        $owner = $this->makeOwner();
        $expected = app(AiChatUsageGuard::class)->evaluate($owner);

        $tool = app(AiToolRegistry::class)->find('usage_status');
        $this->assertNotNull($tool);
        $this->assertTrue($tool->authorize($owner));

        $result = $tool->execute($owner, []);

        $this->assertSame($expected['allowed'], $result['allowed']);
        $this->assertSame($expected['plan']?->name, $result['plan_name']);
    }
}
