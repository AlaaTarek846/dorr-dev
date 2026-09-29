<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AI\Models\AiConversation;
use Modules\AI\Services\AiChatService;
use Modules\Admin\Models\Admin;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Same honesty principle as AiChatImageActionHonestyTest, applied to
 * Phase 8's web-search capability: AiRequiredCapabilityResolver already
 * recognizes phrases like "سعر الدولار اليوم" / "latest news" as needing
 * "web_search", and AiRoutingEngine already prefers a registered model
 * tagged with that capability the same way it does for every other
 * capability - but until an admin actually registers/tags a real
 * "-search-preview"/"-search-api" OpenAI model with "web_search", no
 * candidate will ever match it. Left unhandled, the model would answer a
 * "what's the latest..." question from stale training data while sounding
 * as current as a real search result. This proves systemMessages() injects
 * an explicit, honest notice whenever that gap is detected, and stays
 * silent otherwise - reached by reflection like AiChatImageActionHonestyTest,
 * for the same reason (exercising the full sendMessage() flow needs an
 * unrelated large fixture: routing policy, a live provider, trial control).
 */
class AiChatWebSearchHonestyTest extends TestCase
{
    use RefreshDatabase;

    protected function makeOwner(): Admin
    {
        return Admin::query()->create([
            'name' => 'Test Admin',
            'email' => 'web-search-honesty-owner-'.uniqid().'@example.test',
            'password' => 'password',
            'status' => true,
        ]);
    }

    protected function makeConversation(Admin $owner): AiConversation
    {
        return AiConversation::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getAuthIdentifier(),
            'title' => 'Web search honesty test conversation',
            'provider_key' => 'openai',
        ]);
    }

    protected function callSystemMessages(Admin $owner, AiConversation $conversation, bool $webSearchUnavailable): array
    {
        $method = new ReflectionMethod(AiChatService::class, 'systemMessages');
        $method->setAccessible(true);

        // Positional args match systemMessages()'s full signature; only
        // $webSearchUnavailable (the last one) varies for this test.
        return $method->invoke(app(AiChatService::class), $owner, $conversation, [], null, false, false, false, false, false, $webSearchUnavailable);
    }

    public function test_no_honesty_system_message_is_added_when_not_needed(): void
    {
        $owner = $this->makeOwner();
        $conversation = $this->makeConversation($owner);

        $messages = $this->callSystemMessages($owner, $conversation, false);

        foreach ($messages as $message) {
            $this->assertStringNotContainsString('no live internet access', $message['content']);
        }
    }

    public function test_an_honesty_system_message_is_added_when_web_search_was_requested_but_unavailable(): void
    {
        $owner = $this->makeOwner();
        $conversation = $this->makeConversation($owner);

        $messages = $this->callSystemMessages($owner, $conversation, true);

        $found = collect($messages)->first(
            fn (array $message) => $message['role'] === 'system'
                && str_contains($message['content'], 'no live internet access'),
        );

        $this->assertNotNull($found, 'Expected an honest system-level notice about the missing web-search capability.');
        $this->assertStringContainsString('Do not present an answer as if it reflects the current/live state', $found['content']);
    }
}
