<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AI\Models\AiLearnedIntent;
use Modules\AI\Services\AiLearnedIntentStore;
use Modules\AI\Tests\Feature\Concerns\BuildsAiRoutingScreens;
use Tests\TestCase;

/**
 * Learned phrases as a catalog like countries (no translations - a phrase is matched, not translated): status
 * counts, trash / restore / force delete, bulk delete, ai-learned-intents.* permissions - and a switched-off or
 * trashed phrase never matches and is never switched back on (or re-created) by the router.
 */
class AiLearnedIntentCatalogTest extends TestCase
{
    use BuildsAiRoutingScreens, RefreshDatabase;

    private const BASE = '/api/admin/v1/ai-learned-intents';

    private function row(array $extra = []): AiLearnedIntent
    {
        return AiLearnedIntent::query()->create($extra + [
            'phrase' => 'نفسي اشوف', 'match_mode' => 'phrase', 'intent' => 'image_generation', 'language' => 'ar',
            'confidence' => 1, 'confirmations' => 2, 'is_active' => true,
        ]);
    }

    private function store(): AiLearnedIntentStore
    {
        return app(AiLearnedIntentStore::class);
    }

    public function test_status_counts_trash_restore_and_force_delete(): void
    {
        $this->actingAsScreenAdmin('ai-learned-intents');
        $a = $this->row();
        $b = $this->row(['phrase' => 'ممكن تسمعني', 'intent' => 'voice_reply', 'is_active' => false]);

        $list = $this->getJson(self::BASE.'?status_counts=1')->assertOk()
            ->assertJsonPath('status_counts.total', 2)
            ->assertJsonPath('status_counts.active', 1);
        $this->assertSame(['active', 'pending'], collect($list->json('data'))->sortBy('id')->pluck('state')->values()->all());

        $this->postJson(self::BASE.'/delete-multiple', ['ids' => [$a->id, $b->id]])->assertSuccessful();
        $this->assertSoftDeleted('ai_learned_intents', ['id' => $a->id]);
        $this->assertCount(2, $this->getJson(self::BASE.'?trashed=1')->json('data'));

        $this->postJson(self::BASE."/{$a->id}/restore")->assertSuccessful();
        $this->assertNotSoftDeleted('ai_learned_intents', ['id' => $a->id]);

        $this->deleteJson(self::BASE."/{$b->id}/force")->assertSuccessful();
        $this->assertDatabaseMissing('ai_learned_intents', ['id' => $b->id]);
    }

    public function test_the_same_phrase_in_the_trash_is_restored_not_added_again(): void
    {
        $this->actingAsScreenAdmin('ai-learned-intents');
        $this->row()->delete();

        $this->postJson(self::BASE, ['phrase' => 'نفسي اشوف', 'match_mode' => 'phrase', 'intent' => 'image_generation'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phrase');
    }

    public function test_each_action_needs_its_permission(): void
    {
        $row = $this->row();

        $this->actingAsScreenAdmin('ai-learned-intents', ['view']);
        $this->getJson(self::BASE)->assertOk();
        $this->getJson(self::BASE.'/stats')->assertOk();
        $this->postJson(self::BASE, ['phrase' => 'وريني منظر', 'match_mode' => 'phrase', 'intent' => 'image_generation'])->assertForbidden();
        $this->patchJson(self::BASE."/{$row->id}/status", ['status' => false])->assertForbidden();
        $this->postJson(self::BASE.'/test', ['message' => 'hi'])->assertForbidden();
        $this->postJson(self::BASE.'/delete-multiple', ['ids' => [$row->id]])->assertForbidden();

        $this->actingAsScreenAdmin('ai-learned-intents', ['view', 'multiple-delete']);
        $this->postJson(self::BASE.'/delete-multiple', ['ids' => [$row->id]])->assertSuccessful();

        $this->actingAsScreenAdmin('ai-learned-intents', []);
        $this->getJson(self::BASE)->assertForbidden();
    }

    public function test_a_switched_off_or_trashed_phrase_never_matches(): void
    {
        $row = $this->row();

        $this->assertSame($row->id, $this->store()->match('نفسي اشوف بيت', false)['id'] ?? null);

        $this->actingAsScreenAdmin('ai-learned-intents');
        $this->patchJson(self::BASE."/{$row->id}/status", ['status' => false])->assertOk();
        $this->assertNull($this->store()->match('نفسي اشوف بيت', false), 'the cache is emptied at once');

        $this->patchJson(self::BASE."/{$row->id}/status", ['status' => true])->assertOk();
        $this->assertNotNull($this->store()->match('نفسي اشوف بيت', false));

        $this->deleteJson(self::BASE."/{$row->id}")->assertSuccessful();
        $this->assertNull($this->store()->match('نفسي اشوف بيت', false));
    }

    public function test_the_router_never_switches_on_or_recreates_a_trashed_phrase(): void
    {
        config(['ai.intent_router.learn_min_confirmations' => 2, 'ai.intent_router.learn_min_confidence' => 0.9]);
        $row = $this->row(['is_active' => false, 'confirmations' => 1, 'conflicts' => 0]);
        $row->delete();

        $verdict = ['intents' => ['image_generation'], 'confidence' => 0.95, 'file_format' => null, 'trigger_phrase' => 'نفسي اشوف'];
        $this->store()->learn('نفسي اشوف بيت علي البحر وقت الغروب جميل', $verdict, 'test-model');

        $this->assertSame(1, AiLearnedIntent::query()->withTrashed()->where('phrase', 'نفسي اشوف')->count(), 'not re-created next to the trashed one');
        $trashed = AiLearnedIntent::query()->withTrashed()->findOrFail($row->id);
        $this->assertTrue($trashed->trashed());
        $this->assertFalse($trashed->status, 'confirmed twice now, but still off');
        $this->assertNull($this->store()->match('نفسي اشوف', false));
    }
}
