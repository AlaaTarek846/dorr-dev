<?php

namespace Modules\AI\Tests\Feature;

use App\Enums\UserStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\AI\Models\AiConversation;
use Modules\AI\Models\AiConversationFile;
use Modules\AI\Models\AiFile;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * Phase 10 (doc S27/S39): the real HTTP routes
 * (user/v1/ai-chat/conversations/{conversation}/files) with real
 * Sanctum auth - same layer AiFileApiTest already uses, because that is
 * the actual attack surface doc S25/S26 (cross-owner/tenant isolation)
 * exist for.
 */
class AiConversationFileApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        Bus::fake();
    }

    protected function makeUser(string $label = 'User'): User
    {
        return User::query()->create([
            'name' => $label.' '.uniqid(),
            'email' => 'conv-file-api-'.uniqid().'@example.test',
            'password' => bcrypt('test-password-not-real'),
            'status' => UserStatus::Active,
        ]);
    }

    protected function makeConversation(User $owner): AiConversation
    {
        return AiConversation::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
            'title' => 'Conversation file API test',
        ]);
    }

    protected function makeFile(User $owner, array $overrides = []): AiFile
    {
        return AiFile::query()->create(array_merge([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
            'source_type' => AiFile::SOURCE_UPLOAD,
            'file_name' => 'standalone.txt',
            'file_path' => 'ai-files/test/'.uniqid().'.txt',
            'disk' => 'public',
            'mime_type' => 'text/plain',
            'file_size' => 10,
            'processing_status' => AiFile::STATUS_READY,
        ], $overrides));
    }

    public function test_a_user_can_attach_list_and_detach_their_own_file(): void
    {
        $owner = $this->makeUser();
        Sanctum::actingAs($owner, ['*'], 'user_api');

        $conversation = $this->makeConversation($owner);
        $file = $this->makeFile($owner);

        $attach = $this->postJson("/api/user/v1/ai-chat/conversations/{$conversation->id}/files", ['file_id' => $file->id]);
        $attach->assertOk();

        $this->assertDatabaseHas('ai_conversation_files', [
            'conversation_id' => $conversation->id,
            'file_id' => $file->id,
            'status' => AiConversationFile::STATUS_ATTACHED,
        ]);

        $list = $this->getJson("/api/user/v1/ai-chat/conversations/{$conversation->id}/files");
        $list->assertOk();
        $this->assertCount(1, $list->json('data'));
        $this->assertSame($file->id, $list->json('data.0.id'));

        $detach = $this->deleteJson("/api/user/v1/ai-chat/conversations/{$conversation->id}/files/{$file->id}");
        $detach->assertNoContent();

        $this->assertDatabaseHas('ai_conversation_files', [
            'conversation_id' => $conversation->id,
            'file_id' => $file->id,
            'status' => AiConversationFile::STATUS_DETACHED,
        ]);

        $listAfterDetach = $this->getJson("/api/user/v1/ai-chat/conversations/{$conversation->id}/files");
        $listAfterDetach->assertOk();
        $this->assertCount(0, $listAfterDetach->json('data'));
    }

    public function test_attaching_the_same_file_twice_is_idempotent(): void
    {
        $owner = $this->makeUser();
        Sanctum::actingAs($owner, ['*'], 'user_api');

        $conversation = $this->makeConversation($owner);
        $file = $this->makeFile($owner);

        $this->postJson("/api/user/v1/ai-chat/conversations/{$conversation->id}/files", ['file_id' => $file->id])->assertOk();
        $this->postJson("/api/user/v1/ai-chat/conversations/{$conversation->id}/files", ['file_id' => $file->id])->assertOk();

        $this->assertSame(1, AiConversationFile::query()
            ->where('conversation_id', $conversation->id)
            ->where('file_id', $file->id)
            ->count());
    }

    public function test_detaching_and_reattaching_behaves_predictably(): void
    {
        $owner = $this->makeUser();
        Sanctum::actingAs($owner, ['*'], 'user_api');

        $conversation = $this->makeConversation($owner);
        $file = $this->makeFile($owner);

        $this->postJson("/api/user/v1/ai-chat/conversations/{$conversation->id}/files", ['file_id' => $file->id])->assertOk();
        $this->deleteJson("/api/user/v1/ai-chat/conversations/{$conversation->id}/files/{$file->id}")->assertNoContent();
        $this->postJson("/api/user/v1/ai-chat/conversations/{$conversation->id}/files", ['file_id' => $file->id])->assertOk();

        $this->assertDatabaseHas('ai_conversation_files', [
            'conversation_id' => $conversation->id,
            'file_id' => $file->id,
            'status' => AiConversationFile::STATUS_ATTACHED,
        ]);
        $this->assertSame(1, AiConversationFile::query()
            ->where('conversation_id', $conversation->id)
            ->where('file_id', $file->id)
            ->count());
    }

    public function test_detaching_a_file_never_attached_returns_404(): void
    {
        $owner = $this->makeUser();
        Sanctum::actingAs($owner, ['*'], 'user_api');

        $conversation = $this->makeConversation($owner);
        $file = $this->makeFile($owner);

        $response = $this->deleteJson("/api/user/v1/ai-chat/conversations/{$conversation->id}/files/{$file->id}");
        $response->assertNotFound();
    }

    public function test_a_user_cannot_attach_another_owners_file(): void
    {
        $ownerA = $this->makeUser('A');
        $ownerB = $this->makeUser('B');

        $conversationA = $this->makeConversation($ownerA);
        $fileB = $this->makeFile($ownerB);

        Sanctum::actingAs($ownerA, ['*'], 'user_api');

        $response = $this->postJson("/api/user/v1/ai-chat/conversations/{$conversationA->id}/files", ['file_id' => $fileB->id]);
        $response->assertNotFound();

        $this->assertDatabaseMissing('ai_conversation_files', [
            'conversation_id' => $conversationA->id,
            'file_id' => $fileB->id,
        ]);
    }

    public function test_a_user_cannot_list_or_modify_another_owners_conversation(): void
    {
        $ownerA = $this->makeUser('A');
        $ownerB = $this->makeUser('B');

        $conversationB = $this->makeConversation($ownerB);
        $fileB = $this->makeFile($ownerB);

        Sanctum::actingAs($ownerA, ['*'], 'user_api');

        $this->getJson("/api/user/v1/ai-chat/conversations/{$conversationB->id}/files")->assertNotFound();
        $this->postJson("/api/user/v1/ai-chat/conversations/{$conversationB->id}/files", ['file_id' => $fileB->id])->assertNotFound();
        $this->deleteJson("/api/user/v1/ai-chat/conversations/{$conversationB->id}/files/{$fileB->id}")->assertNotFound();
    }

    public function test_attaching_a_non_ready_file_still_lists_it_but_it_wont_be_searchable(): void
    {
        $owner = $this->makeUser();
        Sanctum::actingAs($owner, ['*'], 'user_api');

        $conversation = $this->makeConversation($owner);
        $processingFile = $this->makeFile($owner, ['processing_status' => AiFile::STATUS_PROCESSING]);

        $this->postJson("/api/user/v1/ai-chat/conversations/{$conversation->id}/files", ['file_id' => $processingFile->id])->assertOk();

        $list = $this->getJson("/api/user/v1/ai-chat/conversations/{$conversation->id}/files");
        $list->assertOk();
        $this->assertCount(1, $list->json('data'), 'attached does not require searchable - doc S4.');
    }
}
