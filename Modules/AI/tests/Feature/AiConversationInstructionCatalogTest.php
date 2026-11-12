<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AI\Models\AiConversation;
use Modules\AI\Models\AiConversationInstruction;
use Modules\AI\Services\AiChatService;
use Modules\AI\Tests\Feature\Concerns\BuildsAiRoutingScreens;
use Modules\User\Models\User;
use ReflectionMethod;
use Tests\TestCase;

/**
 * AI conversation instructions as a catalog like countries, without translations (the instruction is a prompt sent to
 * the AI as it is): status toggle and counts, search, trash / restore / force delete, bulk delete and the
 * ai-conversation-instructions.* permissions per action - and a switched-off or trashed instruction is never sent.
 */
class AiConversationInstructionCatalogTest extends TestCase
{
    use BuildsAiRoutingScreens, RefreshDatabase;

    private const BASE = '/api/admin/v1/ai-conversation-instructions';

    private User $owner;

    private function conversation(): AiConversation
    {
        $this->owner = $this->chatOwner();

        return AiConversation::query()->create([
            'owner_type' => $this->owner->getMorphClass(), 'owner_id' => $this->owner->id, 'title' => 'Chat',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(int $conversationId, array $extra = []): array
    {
        return $extra + [
            'conversation_id' => $conversationId,
            'source_type' => 'system',
            'instruction' => 'Answer in short sentences.',
            'priority' => 5,
            'status' => true,
        ];
    }

    public function test_crud_status_counts_search_trash_and_bulk_delete(): void
    {
        $this->actingAsScreenAdmin('ai-conversation-instructions');
        $conversation = $this->conversation();

        $response = $this->postJson(self::BASE, $this->payload($conversation->id))->assertCreated();
        $id = $response->json('data.id');
        $this->assertSame('Chat', $response->json('data.conversation.title'));
        $this->assertTrue($response->json('data.is_active'));
        $b = $this->postJson(self::BASE, $this->payload($conversation->id, ['instruction' => 'Use bullet points.', 'priority' => null]))->assertCreated();
        $this->assertSame(0, $b->json('data.priority'), 'a cleared priority is 0');
        $b = $b->json('data.id');

        $this->postJson(self::BASE, $this->payload($conversation->id, ['source_type' => 'robot']))->assertUnprocessable()->assertJsonValidationErrors('source_type');
        $this->postJson(self::BASE, $this->payload(999999))->assertUnprocessable()->assertJsonValidationErrors('conversation_id');

        $this->patchJson(self::BASE."/{$id}/status", ['status' => false])->assertOk()->assertJsonPath('data.status', false);
        $this->getJson(self::BASE.'?status_counts=1')->assertOk()
            ->assertJsonPath('status_counts.total', 2)
            ->assertJsonPath('status_counts.inactive', 1);

        $search = json_encode(['searchKey' => 'bullet', 'columns' => ['instruction']]);
        $this->assertSame([$b], collect($this->getJson(self::BASE.'?search='.urlencode($search))->json('data'))->pluck('id')->all());

        $this->deleteJson(self::BASE."/{$id}")->assertSuccessful();
        $this->assertSoftDeleted('ai_conversation_instructions', ['id' => $id]);
        $this->postJson(self::BASE."/{$id}/restore")->assertSuccessful();
        $this->postJson(self::BASE.'/delete-multiple', ['ids' => [$id, $b]])->assertSuccessful();
        $this->assertSoftDeleted('ai_conversation_instructions', ['id' => $b]);
        $this->deleteJson(self::BASE."/{$id}/force")->assertSuccessful();
        $this->assertDatabaseMissing('ai_conversation_instructions', ['id' => $id]);
    }

    public function test_each_action_needs_its_permission(): void
    {
        $conversation = $this->conversation();
        $instruction = AiConversationInstruction::query()->create(['conversation_id' => $conversation->id, 'instruction' => 'Be brief.', 'is_active' => true]);

        $this->actingAsScreenAdmin('ai-conversation-instructions', ['view']);
        $this->getJson(self::BASE)->assertOk();
        $this->postJson(self::BASE, $this->payload($conversation->id))->assertForbidden();
        $this->putJson(self::BASE."/{$instruction->id}", ['instruction' => 'x'])->assertForbidden();
        $this->patchJson(self::BASE."/{$instruction->id}/status", ['status' => false])->assertForbidden();
        $this->deleteJson(self::BASE."/{$instruction->id}")->assertForbidden();
        $this->postJson(self::BASE.'/delete-multiple', ['ids' => [$instruction->id]])->assertForbidden();

        $this->actingAsScreenAdmin('ai-conversation-instructions', ['view', 'update', 'delete']);
        $this->putJson(self::BASE."/{$instruction->id}", ['instruction' => 'Be very brief.'])->assertOk()->assertJsonPath('data.instruction', 'Be very brief.');
        $this->deleteJson(self::BASE."/{$instruction->id}")->assertSuccessful();

        $this->actingAsScreenAdmin('ai-conversation-instructions', []);
        $this->getJson(self::BASE)->assertForbidden();
    }

    public function test_a_switched_off_or_trashed_instruction_is_never_sent(): void
    {
        $conversation = $this->conversation();
        $instruction = AiConversationInstruction::query()->create(['conversation_id' => $conversation->id, 'instruction' => 'SECRET-RULE-42', 'is_active' => true]);
        $sent = function () use ($conversation): bool {
            $method = new ReflectionMethod(AiChatService::class, 'systemMessages');
            $method->setAccessible(true);

            return collect($method->invoke(app(AiChatService::class), $this->owner, $conversation))->pluck('content')->contains('SECRET-RULE-42');
        };

        $this->assertTrue($sent());

        $instruction->update(['status' => false]);
        $this->assertFalse($sent(), 'switched off');

        $instruction->update(['status' => true]);
        $instruction->delete();
        $this->assertFalse($sent(), 'trashed: same as switched off');

        $instruction->restore();
        $this->assertTrue($sent());
    }
}
