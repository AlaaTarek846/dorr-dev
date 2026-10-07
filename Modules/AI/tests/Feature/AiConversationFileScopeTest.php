<?php

namespace Modules\AI\Tests\Feature;

use App\Enums\UserStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AI\Models\AiConversation;
use Modules\AI\Models\AiConversationFile;
use Modules\AI\Models\AiFile;
use Modules\AI\Services\AiConversationFileScope;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * Phase 10 (doc S5/S13/S14/S19): unit-level coverage of the scope
 * resolver's precedence and authorization rules, independent of HTTP
 * (see AiConversationFileApiTest for the route-level/IDOR coverage).
 */
class AiConversationFileScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $label = 'User'): User
    {
        return User::query()->create([
            'name' => $label.' '.uniqid(),
            'email' => 'conv-scope-'.uniqid().'@example.test',
            'password' => bcrypt('test-password-not-real'),
            'status' => UserStatus::Active,
        ]);
    }

    protected function makeConversation(User $owner): AiConversation
    {
        return AiConversation::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
            'title' => 'Scope test',
        ]);
    }

    protected function makeFile(User $owner, array $overrides = []): AiFile
    {
        return AiFile::query()->create(array_merge([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
            'source_type' => AiFile::SOURCE_UPLOAD,
            'file_name' => 'f.txt',
            'file_path' => 'ai-files/test/'.uniqid().'.txt',
            'disk' => 'public',
            'mime_type' => 'text/plain',
            'file_size' => 5,
            'processing_status' => AiFile::STATUS_READY,
        ], $overrides));
    }

    public function test_legacy_conversation_id_file_resolves_without_a_pivot_row(): void
    {
        $owner = $this->makeUser();
        $conversation = $this->makeConversation($owner);
        // Simulates a Phase-1-era file: conversation_id set directly,
        // no ai_conversation_files row at all (pre-Phase-10 data).
        $file = $this->makeFile($owner, ['conversation_id' => $conversation->id]);

        $ids = app(AiConversationFileScope::class)->resolveSearchableFileIds($owner, $conversation);

        $this->assertSame([$file->id], $ids);
    }

    public function test_detaching_a_legacy_file_actually_removes_it_from_scope(): void
    {
        $owner = $this->makeUser();
        $conversation = $this->makeConversation($owner);
        $file = $this->makeFile($owner, ['conversation_id' => $conversation->id]);

        $scope = app(AiConversationFileScope::class);
        $this->assertTrue($scope->detach($conversation, $file));

        $ids = $scope->resolveSearchableFileIds($owner, $conversation);
        $this->assertSame([], $ids, 'a detached legacy file must never resurface via the conversation_id fallback.');
    }

    public function test_attach_is_idempotent_and_reattach_flips_status_back(): void
    {
        $owner = $this->makeUser();
        $conversation = $this->makeConversation($owner);
        $file = $this->makeFile($owner);

        $scope = app(AiConversationFileScope::class);
        $scope->attach($conversation, $file, $owner);
        $scope->attach($conversation, $file, $owner);

        $this->assertSame(1, AiConversationFile::query()->where('conversation_id', $conversation->id)->where('file_id', $file->id)->count());

        $scope->detach($conversation, $file);
        $scope->attach($conversation, $file, $owner);

        $this->assertSame(1, AiConversationFile::query()->where('conversation_id', $conversation->id)->where('file_id', $file->id)->count());
        $this->assertSame([$file->id], $scope->resolveSearchableFileIds($owner, $conversation));
    }

    public function test_explicit_file_ids_drop_a_file_belonging_to_a_different_conversation(): void
    {
        $owner = $this->makeUser();
        $conversationA = $this->makeConversation($owner);
        $conversationB = $this->makeConversation($owner);

        $fileInB = $this->makeFile($owner);
        app(AiConversationFileScope::class)->attach($conversationB, $fileInB, $owner);

        $ids = app(AiConversationFileScope::class)->resolveSearchableFileIds($owner, $conversationA, [$fileInB->id]);

        $this->assertSame([], $ids, 'a file explicitly attached to a DIFFERENT conversation must never be pulled into this one just by naming its id.');
    }

    public function test_explicit_file_ids_allow_and_auto_attach_an_orphan_standalone_upload(): void
    {
        $owner = $this->makeUser();
        $conversation = $this->makeConversation($owner);
        // conversation_id null, never attached anywhere - a standalone
        // /ai-files upload.
        $orphanFile = $this->makeFile($owner);

        $ids = app(AiConversationFileScope::class)->resolveSearchableFileIds($owner, $conversation, [$orphanFile->id]);

        $this->assertSame([$orphanFile->id], $ids);
        $this->assertDatabaseHas('ai_conversation_files', [
            'conversation_id' => $conversation->id,
            'file_id' => $orphanFile->id,
            'status' => AiConversationFile::STATUS_ATTACHED,
        ]);
    }

    public function test_explicit_file_ids_drop_a_file_belonging_to_another_owner(): void
    {
        $ownerA = $this->makeUser('A');
        $ownerB = $this->makeUser('B');
        $conversationA = $this->makeConversation($ownerA);
        $fileB = $this->makeFile($ownerB);

        $ids = app(AiConversationFileScope::class)->resolveSearchableFileIds($ownerA, $conversationA, [$fileB->id]);

        $this->assertSame([], $ids, 'cross-owner file id attack must never leak another owner\'s file into scope.');
    }

    public function test_explicit_file_ids_exclude_a_not_ready_file(): void
    {
        $owner = $this->makeUser();
        $conversation = $this->makeConversation($owner);
        $processingFile = $this->makeFile($owner, ['processing_status' => AiFile::STATUS_PROCESSING]);

        $ids = app(AiConversationFileScope::class)->resolveSearchableFileIds($owner, $conversation, [$processingFile->id]);

        $this->assertSame([], $ids);
    }

    public function test_explicit_file_ids_are_capped_at_the_configured_maximum(): void
    {
        config(['ai.retrieval.multi_file.max_files' => 2]);

        $owner = $this->makeUser();
        $conversation = $this->makeConversation($owner);
        $files = [$this->makeFile($owner), $this->makeFile($owner), $this->makeFile($owner)];

        $ids = app(AiConversationFileScope::class)->resolveSearchableFileIds(
            $owner,
            $conversation,
            array_map(fn (AiFile $f) => $f->id, $files),
        );

        $this->assertCount(2, $ids);
    }

    public function test_conversation_scope_combines_pivot_and_legacy_files_without_duplicates(): void
    {
        $owner = $this->makeUser();
        $conversation = $this->makeConversation($owner);

        $legacyFile = $this->makeFile($owner, ['conversation_id' => $conversation->id]);
        $pivotFile = $this->makeFile($owner);
        app(AiConversationFileScope::class)->attach($conversation, $pivotFile, $owner);

        $ids = app(AiConversationFileScope::class)->resolveSearchableFileIds($owner, $conversation);

        sort($ids);
        $expected = [$legacyFile->id, $pivotFile->id];
        sort($expected);

        $this->assertSame($expected, $ids);
    }

    public function test_no_files_attached_resolves_to_empty_scope(): void
    {
        $owner = $this->makeUser();
        $conversation = $this->makeConversation($owner);

        $ids = app(AiConversationFileScope::class)->resolveSearchableFileIds($owner, $conversation);

        $this->assertSame([], $ids);
    }
}
