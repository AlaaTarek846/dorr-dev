<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AI\Models\AiConversation;
use Modules\AI\Services\AiChatService;
use Modules\Admin\Models\Admin;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Closes the "explain what's in this picture" leg of the image business
 * (alongside AiChatImageActionHonestyTest for generation/editing and
 * AiChatImageEditTest for the real edit path): when a user attaches an
 * image but no vision-capable model actually matched for this request,
 * AiChatService::buildImagePayload() correctly returns null - but before
 * this fix, the model then received nothing except a plain "[an image
 * was attached]" text note, with no signal that it had never actually
 * seen the picture. Left alone, a model asked "what's in this image?"
 * tends to confidently invent a plausible-sounding description instead
 * of admitting it can't see it - a worse failure than an honest refusal,
 * since a hallucinated description reads as correct until the user
 * notices it is wrong. This proves systemMessages() now injects an
 * explicit "you have not actually seen this image" notice whenever that
 * gap is detected, and stays silent otherwise.
 */
class AiChatVisionHonestyTest extends TestCase
{
    use RefreshDatabase;

    protected function makeOwner(): Admin
    {
        return Admin::query()->create([
            'name' => 'Test Admin',
            'email' => 'vision-honesty-owner-'.uniqid().'@example.test',
            'password' => 'password',
            'status' => true,
        ]);
    }

    protected function makeConversation(Admin $owner): AiConversation
    {
        return AiConversation::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getAuthIdentifier(),
            'title' => 'Vision honesty test conversation',
            'provider_key' => 'openai',
        ]);
    }

    protected function callSystemMessages(Admin $owner, AiConversation $conversation, bool $imageUnviewable): array
    {
        $method = new ReflectionMethod(AiChatService::class, 'systemMessages');
        $method->setAccessible(true);

        return $method->invoke(app(AiChatService::class), $owner, $conversation, [], null, false, false, $imageUnviewable);
    }

    public function test_no_vision_honesty_message_is_added_when_not_needed(): void
    {
        $owner = $this->makeOwner();
        $conversation = $this->makeConversation($owner);

        $messages = $this->callSystemMessages($owner, $conversation, false);

        foreach ($messages as $message) {
            $this->assertStringNotContainsString('you have NOT actually seen this image', $message['content']);
        }
    }

    public function test_a_vision_honesty_message_is_added_when_the_attached_image_cannot_actually_be_seen(): void
    {
        $owner = $this->makeOwner();
        $conversation = $this->makeConversation($owner);

        $messages = $this->callSystemMessages($owner, $conversation, true);

        $found = collect($messages)->first(
            fn (array $message) => $message['role'] === 'system'
                && str_contains($message['content'], 'you have NOT actually seen this image'),
        );

        $this->assertNotNull($found, 'Expected an honest system-level notice that the model never received the image content.');
        $this->assertStringContainsString('Do NOT guess, assume, or invent', $found['content']);
    }
}
