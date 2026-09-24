<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AI\Models\AiConversation;
use Modules\AI\Models\AiConversationAttachment;
use Modules\AI\Models\AiMessage;
use Modules\AI\Services\AiChatService;
use Modules\Admin\Models\Admin;
use Tests\TestCase;

/**
 * v2.0 requirements doc S17.3: self-service export and erase must cover
 * only the requesting owner's own conversations - never another owner's
 * - and export must actually return usable conversation/message/
 * attachment content, not just IDs.
 */
class AiChatServiceDataExportEraseTest extends TestCase
{
    use RefreshDatabase;

    protected function makeOwner(): Admin
    {
        return Admin::query()->create([
            'name' => 'Test Admin',
            'email' => 'export-owner-'.uniqid().'@example.test',
            'password' => 'password',
            'status' => true,
        ]);
    }

    protected function makeConversationWithMessage(Admin $owner, string $content = 'Hello there'): AiConversation
    {
        $conversation = AiConversation::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getAuthIdentifier(),
            'title' => 'Test conversation',
            'provider_key' => 'openai',
        ]);

        $message = AiMessage::query()->create([
            'conversation_id' => $conversation->id,
            'sequence_number' => 1,
            'role' => 'user',
            'content' => $content,
        ]);

        AiConversationAttachment::query()->create([
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'file_name' => 'invoice.pdf',
            'file_path' => 'ai-attachments/invoice.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 12345,
        ]);

        return $conversation;
    }

    public function test_export_includes_the_owners_conversations_messages_and_attachments(): void
    {
        $owner = $this->makeOwner();
        $this->makeConversationWithMessage($owner, 'What services do you offer?');

        $export = app(AiChatService::class)->exportOwnerData($owner);

        $this->assertSame($owner->getMorphClass(), $export['owner_type']);
        $this->assertSame($owner->getAuthIdentifier(), $export['owner_id']);
        $this->assertCount(1, $export['conversations']);
        $this->assertCount(1, $export['conversations'][0]['messages']);
        $this->assertSame('What services do you offer?', $export['conversations'][0]['messages'][0]['content']);
        $this->assertCount(1, $export['conversations'][0]['messages'][0]['attachments']);
        $this->assertSame('invoice.pdf', $export['conversations'][0]['messages'][0]['attachments'][0]['file_name']);
    }

    public function test_export_never_includes_another_owners_conversations(): void
    {
        $owner = $this->makeOwner();
        $otherOwner = $this->makeOwner();

        $this->makeConversationWithMessage($owner, 'My own message');
        $this->makeConversationWithMessage($otherOwner, 'Someone elses private message');

        $export = app(AiChatService::class)->exportOwnerData($owner);

        $this->assertCount(1, $export['conversations']);
        $this->assertSame('My own message', $export['conversations'][0]['messages'][0]['content']);
    }

    public function test_erase_deletes_all_of_the_owners_conversations_and_reports_the_count(): void
    {
        $owner = $this->makeOwner();
        $this->makeConversationWithMessage($owner, 'First conversation');
        $this->makeConversationWithMessage($owner, 'Second conversation');

        $response = app(AiChatService::class)->eraseOwnerData($owner);
        $payload = json_decode($response->getContent(), true);

        $this->assertSame(2, $payload['data']['deleted_conversations']);
        $this->assertSame(0, AiConversation::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getAuthIdentifier())
            ->count());
    }

    public function test_erase_never_deletes_another_owners_conversations(): void
    {
        $owner = $this->makeOwner();
        $otherOwner = $this->makeOwner();

        $this->makeConversationWithMessage($owner, 'Delete me');
        $untouched = $this->makeConversationWithMessage($otherOwner, 'Keep me');

        app(AiChatService::class)->eraseOwnerData($owner);

        $this->assertDatabaseHas('ai_conversations', ['id' => $untouched->id]);
    }

    public function test_erase_cascades_to_messages_and_attachments(): void
    {
        $owner = $this->makeOwner();
        $conversation = $this->makeConversationWithMessage($owner, 'Delete this whole thread');
        $messageId = $conversation->messages()->first()->id;

        app(AiChatService::class)->eraseOwnerData($owner);

        $this->assertDatabaseMissing('ai_messages', ['id' => $messageId]);
        $this->assertDatabaseMissing('ai_conversation_attachments', ['message_id' => $messageId]);
    }
}
