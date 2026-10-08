<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\Admin;
use Modules\AI\Http\Resources\AiMessageResource;
use Modules\AI\Models\AiConversation;
use Modules\AI\Models\AiMessage;
use Tests\TestCase;

/**
 * Regression test for a real bug: AiChatService::sendMessage() used to set
 * generated_file / confidence_score / verification_warnings as dynamic
 * (non-column) attributes on the freshly-created AiMessage and never saved
 * them - they rendered correctly in that one request's JSON response
 * (AiMessageResource reads them off the in-memory model) but were silently
 * lost forever on any later reload, because ai_messages had no such
 * columns at all. The fix adds real nullable columns and persists them at
 * creation time. This test proves the data survives a fresh reload from
 * the database, not just the in-memory instance that created it.
 */
class AiMessageVerificationSnapshotPersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected function makeOwner(): Admin
    {
        return Admin::query()->create([
            'name' => 'Snapshot Owner',
            'email' => 'snapshot-owner-'.uniqid().'@example.test',
            'password' => bcrypt('test-password-not-real'),
        ]);
    }

    protected function makeConversation(Admin $owner): AiConversation
    {
        return AiConversation::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
            'title' => 'Snapshot persistence test',
        ]);
    }

    public function test_generated_file_confidence_score_and_warnings_survive_a_fresh_reload(): void
    {
        $owner = $this->makeOwner();
        $conversation = $this->makeConversation($owner);

        $generatedFile = ['name' => 'report.pdf', 'url' => 'https://example.test/storage/report.pdf'];
        $warnings = [__('ai.verification_low_confidence_notice')];

        $message = $conversation->messages()->create([
            'role' => AiMessage::ROLE_ASSISTANT,
            'content' => 'Here is the answer, with a generated file attached.',
            'provider_key' => 'openai',
            'model' => 'gpt-test',
            'tokens_used' => 42,
            'is_error' => false,
            'generated_file' => $generatedFile,
            'confidence_score' => 0.812,
            'verification_warnings' => $warnings,
        ]);

        // Same instance, still in memory - would have passed even under
        // the old (buggy) dynamic-attribute behaviour.
        $this->assertSame($generatedFile, $message->generated_file);

        // The actual regression: reload from the database with a brand
        // new model instance, simulating the conversation being refetched
        // on a later page load.
        $reloaded = AiMessage::query()->findOrFail($message->id);

        // assertEquals, not assertSame, for this one field specifically:
        // MySQL's native JSON column type does not guarantee object
        // member order on round trip (its binary JSON representation
        // reorders keys internally, e.g. by key length, for efficient
        // lookup) - the VALUES are still exactly correct, only the key
        // order can legitimately differ, which assertSame's strict
        // === array comparison treats as a mismatch even though nothing
        // is actually wrong. Every other assertion below stays assertSame
        // - $warnings is a single-element list where order is moot, and
        // confidence_score is a scalar.
        $this->assertEquals($generatedFile, $reloaded->generated_file);
        $this->assertEquals(0.812, (float) $reloaded->confidence_score);
        $this->assertSame($warnings, $reloaded->verification_warnings);

        $resource = (new AiMessageResource($reloaded))->resolve();

        $this->assertEquals($generatedFile, $resource['generated_file']);
        $this->assertEquals(0.812, (float) $resource['confidence_score']);
        $this->assertSame($warnings, $resource['verification_warnings']);
    }

    public function test_the_three_snapshot_fields_are_null_and_omitted_when_nothing_was_generated_or_flagged(): void
    {
        $owner = $this->makeOwner();
        $conversation = $this->makeConversation($owner);

        $message = $conversation->messages()->create([
            'role' => AiMessage::ROLE_ASSISTANT,
            'content' => 'A plain, unflagged answer with no generated file.',
            'provider_key' => 'openai',
            'model' => 'gpt-test',
            'tokens_used' => 10,
            'is_error' => false,
            'generated_file' => null,
            'confidence_score' => null,
            'verification_warnings' => null,
        ]);

        $reloaded = AiMessage::query()->findOrFail($message->id);

        $this->assertNull($reloaded->generated_file);
        $this->assertNull($reloaded->confidence_score);
        $this->assertNull($reloaded->verification_warnings);

        $resource = (new AiMessageResource($reloaded))->resolve();

        $this->assertArrayNotHasKey('generated_file', $resource);
        $this->assertArrayNotHasKey('confidence_score', $resource);
        $this->assertArrayNotHasKey('verification_warnings', $resource);
    }
}
