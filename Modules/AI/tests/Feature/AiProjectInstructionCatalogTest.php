<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AI\Models\AiConversation;
use Modules\AI\Models\AiProject;
use Modules\AI\Models\AiProjectConversation;
use Modules\AI\Models\AiProjectInstruction;
use Modules\AI\Services\AiUserProjectService;
use Modules\AI\Tests\Feature\Concerns\BuildsAiRoutingScreens;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * AI project instructions as a catalog like countries, without translations (the instruction is a prompt sent to the
 * AI as it is): status toggle and counts, trash / restore / force delete, bulk delete and the
 * ai-project-instructions.* permissions per action - and a switched-off or trashed instruction is never sent nor shown
 * in the person's project.
 */
class AiProjectInstructionCatalogTest extends TestCase
{
    use BuildsAiRoutingScreens, RefreshDatabase;

    private const BASE = '/api/admin/v1/ai-project-instructions';

    private User $owner;

    private function project(): AiProject
    {
        $this->owner = $this->chatOwner();

        return AiProject::query()->create([
            'owner_type' => $this->owner->getMorphClass(), 'owner_id' => $this->owner->id, 'name' => 'My project',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(int $projectId, array $extra = []): array
    {
        return $extra + [
            'project_id' => $projectId,
            'instruction' => 'Always follow the approved architecture.',
            'priority' => 3,
            'status' => true,
        ];
    }

    public function test_crud_status_counts_trash_and_bulk_delete(): void
    {
        $this->actingAsScreenAdmin('ai-project-instructions');
        $project = $this->project();

        $response = $this->postJson(self::BASE, $this->payload($project->id))->assertCreated();
        $id = $response->json('data.id');
        $this->assertSame('My project', $response->json('data.project.name'));
        $this->assertTrue($response->json('data.is_active'));
        $b = $this->postJson(self::BASE, $this->payload($project->id, ['priority' => '']))->assertCreated();
        $this->assertSame(0, $b->json('data.priority'));
        $b = $b->json('data.id');

        $this->postJson(self::BASE, $this->payload(999999))->assertUnprocessable()->assertJsonValidationErrors('project_id');
        $this->postJson(self::BASE, $this->payload($project->id, ['instruction' => '']))->assertUnprocessable()->assertJsonValidationErrors('instruction');

        $this->patchJson(self::BASE."/{$id}/status", ['status' => false])->assertOk()->assertJsonPath('data.status', false);
        $this->getJson(self::BASE.'?status_counts=1')->assertOk()
            ->assertJsonPath('status_counts.total', 2)
            ->assertJsonPath('status_counts.inactive', 1);

        $this->deleteJson(self::BASE."/{$id}")->assertSuccessful();
        $this->assertSoftDeleted('ai_project_instructions', ['id' => $id]);
        $this->postJson(self::BASE."/{$id}/restore")->assertSuccessful();
        $this->postJson(self::BASE.'/delete-multiple', ['ids' => [$id, $b]])->assertSuccessful();
        $this->assertSoftDeleted('ai_project_instructions', ['id' => $b]);
        $this->deleteJson(self::BASE."/{$id}/force")->assertSuccessful();
        $this->assertDatabaseMissing('ai_project_instructions', ['id' => $id]);
    }

    public function test_each_action_needs_its_permission(): void
    {
        $project = $this->project();
        $instruction = AiProjectInstruction::query()->create(['project_id' => $project->id, 'instruction' => 'Be brief.', 'is_active' => true]);

        $this->actingAsScreenAdmin('ai-project-instructions', ['view']);
        $this->getJson(self::BASE)->assertOk();
        $this->postJson(self::BASE, $this->payload($project->id))->assertForbidden();
        $this->putJson(self::BASE."/{$instruction->id}", ['instruction' => 'x'])->assertForbidden();
        $this->patchJson(self::BASE."/{$instruction->id}/status", ['status' => false])->assertForbidden();
        $this->deleteJson(self::BASE."/{$instruction->id}")->assertForbidden();
        $this->postJson(self::BASE.'/delete-multiple', ['ids' => [$instruction->id]])->assertForbidden();

        $this->actingAsScreenAdmin('ai-project-instructions', ['view', 'create', 'multiple-delete']);
        $this->postJson(self::BASE, $this->payload($project->id))->assertCreated();
        $this->postJson(self::BASE.'/delete-multiple', ['ids' => [$instruction->id]])->assertSuccessful();

        $this->actingAsScreenAdmin('ai-project-instructions', []);
        $this->getJson(self::BASE)->assertForbidden();
    }

    public function test_a_switched_off_or_trashed_instruction_is_never_sent_nor_shown(): void
    {
        $project = $this->project();
        $conversation = AiConversation::query()->create([
            'owner_type' => $this->owner->getMorphClass(), 'owner_id' => $this->owner->id, 'title' => 'Chat',
        ]);
        AiProjectConversation::query()->create(['project_id' => $project->id, 'conversation_id' => $conversation->id]);
        $instruction = AiProjectInstruction::query()->create(['project_id' => $project->id, 'instruction' => 'SECRET-RULE-42', 'is_active' => true]);
        $projects = app(AiUserProjectService::class);
        $sent = fn (): bool => str_contains((string) $projects->systemMessage($conversation), 'SECRET-RULE-42');
        $shown = fn (): bool => $projects->show($this->owner, $project->id)->instructions->contains('id', $instruction->id);

        $this->assertTrue($sent());
        $this->assertTrue($shown());

        $instruction->update(['status' => false]);
        $this->assertFalse($sent(), 'switched off');
        $this->assertFalse($shown());

        $instruction->update(['status' => true]);
        $instruction->delete();
        $this->assertFalse($sent(), 'trashed: same as switched off');
        $this->assertFalse($shown());

        $instruction->restore();
        $this->assertTrue($sent());
    }
}
