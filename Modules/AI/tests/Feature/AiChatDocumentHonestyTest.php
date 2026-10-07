<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AI\Models\AiConversation;
use Modules\AI\Services\AiChatService;
use Modules\Admin\Models\Admin;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Document equivalent of AiChatVisionHonestyTest: real, observed bug - a
 * non-image attachment (pdf/docx/...) whose text extraction returns null
 * (most commonly a scanned/photographed receipt or document with no real
 * text layer, or an encoding AiDocumentTextExtractor's dependency-free
 * fallback cannot decode - this project does not have "smalot/pdfparser"
 * installed, so every PDF goes through that limited fallback) silently
 * left the model with only a bare "[file X of type Y was attached]" note
 * and zero actual content, with no honesty flag the way images already
 * had via $imageUnviewable. The model then either denied a file was ever
 * attached, or gave a vague, unconfident non-answer - both confusing,
 * neither honest about the real reason. This proves systemMessages() now
 * injects an explicit "could not read this file's content" notice
 * whenever that gap is detected, and stays silent otherwise.
 */
class AiChatDocumentHonestyTest extends TestCase
{
    use RefreshDatabase;

    protected function makeOwner(): Admin
    {
        return Admin::query()->create([
            'name' => 'Test Admin',
            'email' => 'document-honesty-owner-'.uniqid().'@example.test',
            'password' => 'password',
            'status' => true,
        ]);
    }

    protected function makeConversation(Admin $owner): AiConversation
    {
        return AiConversation::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getAuthIdentifier(),
            'title' => 'Document honesty test conversation',
            'provider_key' => 'openai',
        ]);
    }

    protected function callSystemMessages(Admin $owner, AiConversation $conversation, bool $documentUnviewable): array
    {
        $method = new ReflectionMethod(AiChatService::class, 'systemMessages');
        $method->setAccessible(true);

        return $method->invoke(
            app(AiChatService::class), $owner, $conversation, [], null, false, false, false, false, false, false, null, $documentUnviewable,
        );
    }

    public function test_no_document_honesty_message_is_added_when_not_needed(): void
    {
        $owner = $this->makeOwner();
        $conversation = $this->makeConversation($owner);

        $messages = $this->callSystemMessages($owner, $conversation, false);

        foreach ($messages as $message) {
            $this->assertStringNotContainsString('you have NOT actually read this file', $message['content']);
        }
    }

    public function test_a_document_honesty_message_is_added_when_the_attached_file_could_not_be_read(): void
    {
        $owner = $this->makeOwner();
        $conversation = $this->makeConversation($owner);

        $messages = $this->callSystemMessages($owner, $conversation, true);

        $found = collect($messages)->first(
            fn (array $message) => $message['role'] === 'system'
                && str_contains($message['content'], 'could not be extracted'),
        );

        $this->assertNotNull($found, 'Expected an honest system-level notice that the document text could not be read.');
        $this->assertStringContainsString('Do NOT guess, assume, or invent', $found['content']);
        $this->assertStringContainsString('do NOT claim no file was attached', $found['content']);
    }
}
