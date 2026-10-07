<?php

namespace Modules\AI\Tests\Feature;

use App\Enums\UserStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\AI\Models\AiConversation;
use Modules\AI\Models\AiConversationAttachment;
use Modules\AI\Models\AiMessage;
use Modules\Admin\Models\Admin;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * Business gap fix (admin dashboard review, 27 Sep): the admin's
 * ai-conversation-attachments screen has always displayed a "Conversation"
 * column, a "Message ID" column and a "Created at" column, but
 * AiConversationAttachmentResource never returned those three fields -
 * they rendered as "-" in every single row even though the relations
 * (conversation, message) were already eager-loaded by
 * AiConversationAttachmentRepository. Fixed by adding conversation,
 * message_id and created_at to the resource's toArray().
 */
class AiConversationAttachmentAdminResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function actingAsAdmin(): Admin
    {
        $admin = Admin::query()->create([
            'name' => 'Test Admin',
            'email' => 'attachment-admin-'.uniqid().'@example.test',
            'password' => 'password',
            'status' => true,
        ]);

        Sanctum::actingAs($admin, ['*'], 'admin_api');

        return $admin;
    }

    public function test_the_admin_index_endpoint_returns_conversation_message_id_and_created_at(): void
    {
        $this->actingAsAdmin();

        $owner = User::query()->create([
            'name' => 'Attachment Owner',
            'email' => 'owner-'.uniqid().'@example.test',
            'password' => bcrypt('test-password-not-real'),
            'status' => UserStatus::Active,
        ]);

        $conversation = AiConversation::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
            'title' => 'Attachment resource test conversation',
        ]);

        $message = AiMessage::query()->create([
            'conversation_id' => $conversation->id,
            'sequence_number' => 1,
            'role' => 'user',
            'content' => 'Here is a file',
        ]);

        $attachment = AiConversationAttachment::query()->create([
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'file_name' => 'test.png',
            'file_path' => 'ai-chat/test.png',
            'mime_type' => 'image/png',
            'file_size' => 1234,
        ]);

        $response = $this->getJson('/api/admin/v1/ai-conversation-attachments');

        $response->assertOk();

        $payload = collect($response->json('data'))->firstWhere('id', $attachment->id);

        $this->assertNotNull($payload, 'The created attachment was not found in the index response.');
        $this->assertSame($conversation->id, $payload['conversation']['id'] ?? null);
        $this->assertSame($conversation->title, $payload['conversation']['title'] ?? null);
        $this->assertSame($message->id, $payload['message_id'] ?? null);
        $this->assertNotNull($payload['created_at'] ?? null);
    }
}
