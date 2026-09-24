<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\AI\Models\AiConversation;
use Modules\AI\Models\AiConversationAttachment;
use Modules\AI\Repositories\AiConversationRepository;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * v2.0 requirements doc S17.3 (retention + authorized deletion). Real gap
 * found during the final checklist pass: ai_conversation_attachments uses
 * a DB-level cascadeOnDelete(), which removes the *rows* when a
 * conversation is deleted but never fires an Eloquent model event and
 * never touches the actual file on the "public" disk - so an owner's
 * "erase my data" request was leaving every uploaded file physically on
 * disk. AiConversation::booted() now deletes the real file before the
 * row cascade runs; this uses Storage::fake() (a real, isolated
 * filesystem swap - not a mock of the delete call) so the assertions
 * prove an actual file was actually removed.
 */
class AiConversationAttachmentCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(): User
    {
        return User::query()->create([
            'name' => 'Cleanup Test User',
            'email' => 'cleanup-'.uniqid().'@example.test',
            'password' => bcrypt('test-password-not-real'),
            'status' => true,
        ]);
    }

    public function test_deleting_a_conversation_deletes_its_attachment_files_from_disk(): void
    {
        Storage::fake('public');

        $owner = $this->makeUser();
        $conversation = AiConversation::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
            'title' => 'Cleanup test',
        ]);

        $path = 'ai-chat/'.$owner->getMorphClass().'/'.$owner->id.'/fake-attachment.png';
        Storage::disk('public')->put($path, 'fake file contents');

        AiConversationAttachment::query()->create([
            'conversation_id' => $conversation->id,
            'message_id' => null,
            'file_name' => 'fake-attachment.png',
            'file_path' => $path,
            'mime_type' => 'image/png',
            'file_size' => 19,
        ]);

        $this->assertTrue(Storage::disk('public')->exists($path));

        app(AiConversationRepository::class)->deleteForOwner($owner, $conversation->id);

        $this->assertFalse(Storage::disk('public')->exists($path), 'the physical file must be deleted, not just the DB row');
        $this->assertSame(0, AiConversationAttachment::query()->where('conversation_id', $conversation->id)->count());
    }

    public function test_a_conversation_with_no_attachments_deletes_cleanly(): void
    {
        Storage::fake('public');

        $owner = $this->makeUser();
        $conversation = AiConversation::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
            'title' => 'No attachments',
        ]);

        $this->assertTrue(app(AiConversationRepository::class)->deleteForOwner($owner, $conversation->id));
    }
}
