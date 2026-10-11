<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\Admin;
use Modules\AI\Services\AiChatService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Proves AiChatService::resolveToolContext() - the actual hook wired into
 * sendMessage() right before buildHistory() - is reached and returns a
 * real, owner-scoped result, not just that AiToolResolver/AiToolRegistry
 * work in isolation. Reached by reflection rather than a full
 * sendMessage() HTTP round trip, same trade-off AiChatBroadcastTest
 * documents: a live provider/routing/trial fixture has nothing to do
 * with what this method adds.
 */
class AiChatServiceToolContextTest extends TestCase
{
    use RefreshDatabase;

    protected function makeOwner(): Admin
    {
        return Admin::query()->create([
            'name' => 'Test Admin',
            'email' => 'tool-context-owner-'.uniqid().'@example.test',
            'password' => 'password',
            'status' => true,
        ]);
    }

    protected function callResolveToolContext(Admin $owner, string $content): ?string
    {
        $method = new ReflectionMethod(AiChatService::class, 'resolveToolContext');
        $method->setAccessible(true);

        return $method->invoke(app(AiChatService::class), $owner, $content);
    }

    public function test_a_usage_question_returns_a_system_note_carrying_the_real_tool_result(): void
    {
        $owner = $this->makeOwner();

        $context = $this->callResolveToolContext($owner, 'كام رسالة باقيلي النهارده؟');

        $this->assertNotNull($context);
        $this->assertStringContainsString('usage_status', $context);
        $this->assertStringContainsString('"allowed"', $context);
    }

    public function test_an_unrelated_message_triggers_no_tool_and_returns_null(): void
    {
        $owner = $this->makeOwner();

        $context = $this->callResolveToolContext($owner, 'اكتبلي دالة PHP بترجع مجموع رقمين');

        $this->assertNull($context);
    }
}
