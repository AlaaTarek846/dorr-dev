<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\Admin;
use Modules\AI\Models\AiConversation;
use Modules\AI\Services\AiChatService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Real, observed gap: "image_generation" already exists as a capability
 * tag and AiRequiredCapabilityResolver already recognizes phrases like
 * "edit this image" / "عدل الصورة" - but no connector in this codebase
 * actually calls an image generation/editing API, only the text
 * chat/completions endpoint. Left alone, the model would write a reply
 * that sounds like an edited/generated image file is coming and then
 * never deliver one. This proves systemMessages() injects an explicit,
 * honest system instruction whenever that gap is detected, and stays
 * silent otherwise - reached by reflection like AiChatBroadcastTest,
 * since exercising the full sendMessage() flow needs an unrelated large
 * fixture (routing policy, a live provider, trial control).
 */
class AiChatImageActionHonestyTest extends TestCase
{
    use RefreshDatabase;

    protected function makeOwner(): Admin
    {
        return Admin::query()->create([
            'name' => 'Test Admin',
            'email' => 'image-honesty-owner-'.uniqid().'@example.test',
            'password' => 'password',
            'status' => true,
        ]);
    }

    protected function makeConversation(Admin $owner): AiConversation
    {
        return AiConversation::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getAuthIdentifier(),
            'title' => 'Image honesty test conversation',
            'provider_key' => 'openai',
        ]);
    }

    protected function callSystemMessages(Admin $owner, AiConversation $conversation, bool $imageActionUnavailable): array
    {
        $method = new ReflectionMethod(AiChatService::class, 'systemMessages');
        $method->setAccessible(true);

        return $method->invoke(app(AiChatService::class), $owner, $conversation, [], null, $imageActionUnavailable);
    }

    public function test_no_honesty_system_message_is_added_when_not_needed(): void
    {
        $owner = $this->makeOwner();
        $conversation = $this->makeConversation($owner);

        $messages = $this->callSystemMessages($owner, $conversation, false);

        foreach ($messages as $message) {
            $this->assertStringNotContainsString('cannot produce or return an actual image file', $message['content']);
        }
    }

    public function test_an_honesty_system_message_is_added_when_image_editing_was_requested_but_unavailable(): void
    {
        $owner = $this->makeOwner();
        $conversation = $this->makeConversation($owner);

        $messages = $this->callSystemMessages($owner, $conversation, true);

        $found = collect($messages)->first(
            fn (array $message) => $message['role'] === 'system'
                && str_contains($message['content'], 'cannot produce or return an actual image file'),
        );

        $this->assertNotNull($found, 'Expected an honest system-level notice about the missing image generation/editing capability.');
        $this->assertStringContainsString('Do not say you will make, prepare, generate, attach or send', $found['content']);
    }
}
