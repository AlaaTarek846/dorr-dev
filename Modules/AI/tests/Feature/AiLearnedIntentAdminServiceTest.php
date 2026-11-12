<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\AI\Models\AiLearnedIntent;
use Tests\Support\AdminPermissions;
use Tests\TestCase;

/**
 * The admin side of the learned dictionary: creating, switching on/off,
 * deleting, listing, stats and the side-effect-free "try a phrase" panel.
 * (The catalog behaviour - permissions, counts, trash - is in AiLearnedIntentCatalogTest.)
 */
class AiLearnedIntentAdminServiceTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/admin/v1/ai-learned-intents';

    protected function setUp(): void
    {
        parent::setUp();

        // phpunit.xml switches the router off for every other test; the "try a phrase" preview reports its state.
        config(['ai.intent_router.enabled' => true]);

        $admin = Admin::query()->create([
            'name' => 'Learned Admin', 'email' => 'learned-'.uniqid().'@example.test', 'password' => 'password', 'status' => true,
        ]);

        Sanctum::actingAs(AdminPermissions::grant($admin), ['*'], 'admin_api');
    }

    public function test_an_admin_phrase_is_normalized_and_active_immediately(): void
    {
        $this->postJson(self::BASE, ['phrase' => 'نَفسى اشوف', 'match_mode' => 'phrase', 'intent' => 'image_generation'])->assertCreated();

        $this->assertDatabaseHas('ai_learned_intents', [
            'phrase' => 'نفسي اشوف', 'intent' => 'image_generation', 'status' => true, 'source' => 'admin', 'conflicts' => 0,
        ]);
    }

    public function test_the_file_format_is_only_kept_for_file_output(): void
    {
        $this->postJson(self::BASE, ['phrase' => 'جهزهالي كشيت', 'match_mode' => 'phrase', 'intent' => 'file_output', 'file_format' => 'xlsx'])->assertCreated();
        $this->postJson(self::BASE, ['phrase' => 'قولهالي بصوتك', 'match_mode' => 'phrase', 'intent' => 'voice_reply', 'file_format' => 'pdf'])->assertCreated();

        $this->assertSame('xlsx', AiLearnedIntent::query()->where('intent', 'file_output')->value('file_format'));
        $this->assertNull(AiLearnedIntent::query()->where('intent', 'voice_reply')->value('file_format'));
    }

    public function test_switching_off_marks_it_untrusted_and_switching_on_clears_that(): void
    {
        $row = AiLearnedIntent::query()->create([
            'phrase' => 'نفسي اشوف', 'match_mode' => 'phrase', 'intent' => 'image_generation', 'language' => 'ar',
            'confidence' => 0.95, 'confirmations' => 2, 'is_active' => true,
        ]);

        $this->patchJson(self::BASE."/{$row->id}/status", ['status' => false])->assertOk()->assertJsonPath('data.state', 'conflict');
        $row->refresh();
        $this->assertFalse($row->is_active);
        $this->assertGreaterThan(0, $row->conflicts, 'the router must never switch it back on by itself');

        $this->patchJson(self::BASE."/{$row->id}/status", ['status' => true])->assertOk()->assertJsonPath('data.state', 'active');
        $row->refresh();
        $this->assertTrue($row->is_active);
        $this->assertSame(0, $row->conflicts);

        // The edit form's switch follows the same rule.
        $this->putJson(self::BASE."/{$row->id}", ['status' => false])->assertOk();
        $this->assertGreaterThan(0, $row->fresh()->conflicts);
    }

    public function test_changing_the_intent_to_one_that_already_exists_is_rejected(): void
    {
        $a = AiLearnedIntent::query()->create(['phrase' => 'وريني منظر', 'match_mode' => 'phrase', 'intent' => 'image_generation', 'language' => 'ar', 'confidence' => 1, 'is_active' => true]);
        AiLearnedIntent::query()->create(['phrase' => 'وريني منظر', 'match_mode' => 'phrase', 'intent' => 'web_search', 'language' => 'ar', 'confidence' => 1, 'is_active' => true]);

        $this->putJson(self::BASE."/{$a->id}", ['intent' => 'web_search'])->assertUnprocessable()->assertJsonValidationErrors('intent');
    }

    public function test_delete_stats_and_filters(): void
    {
        $active = AiLearnedIntent::query()->create(['phrase' => 'نفسي اشوف', 'match_mode' => 'phrase', 'intent' => 'image_generation', 'language' => 'ar', 'confidence' => 1, 'is_active' => true, 'hits' => 5]);
        AiLearnedIntent::query()->create(['phrase' => 'ممكن تسمعني', 'match_mode' => 'phrase', 'intent' => 'voice_reply', 'language' => 'ar', 'confidence' => 0.9, 'is_active' => false]);
        AiLearnedIntent::query()->create(['phrase' => 'وريني منظر', 'match_mode' => 'phrase', 'intent' => 'web_search', 'language' => 'ar', 'confidence' => 0.9, 'is_active' => false, 'conflicts' => 1]);

        $stats = $this->getJson(self::BASE.'/stats')->assertOk()->json('data');
        $this->assertSame(3, $stats['total']);
        $this->assertSame(1, $stats['active']);
        $this->assertSame(1, $stats['pending']);
        $this->assertSame(1, $stats['conflicts']);
        $this->assertSame(5, $stats['hits']);

        $this->assertCount(1, $this->getJson(self::BASE.'?state=conflict')->assertOk()->json('data'));
        $this->assertCount(1, $this->getJson(self::BASE.'?state=pending')->assertOk()->json('data'));
        $this->assertCount(1, $this->getJson(self::BASE.'?intent=image_generation')->assertOk()->json('data'));

        $search = json_encode(['searchKey' => 'نَفسى', 'columns' => ['phrase']]);
        $this->assertCount(1, $this->getJson(self::BASE.'?search='.urlencode($search))->assertOk()->json('data'), 'search normalizes spelling too');

        $this->deleteJson(self::BASE."/{$active->id}")->assertSuccessful();
        $this->assertSoftDeleted('ai_learned_intents', ['id' => $active->id]);
    }

    public function test_try_a_phrase_has_no_side_effects(): void
    {
        $row = AiLearnedIntent::query()->create(['phrase' => 'نفسي اشوف', 'match_mode' => 'phrase', 'intent' => 'image_generation', 'language' => 'ar', 'confidence' => 1, 'is_active' => true]);

        $learned = $this->postJson(self::BASE.'/test', ['message' => 'نفسي اشوف بيت على البحر'])->assertOk()->json('data');
        $this->assertSame('learned', $learned['stage']);
        $this->assertSame(['image_generation'], $learned['intents']);
        $this->assertSame(0, $row->fresh()->hits, 'testing must not count as a use');

        $lexicon = $this->postJson(self::BASE.'/test', ['message' => 'اعمل ملف pdf عن مصر'])->assertOk()->json('data');
        $this->assertSame('lexicon', $lexicon['stage']);
        $this->assertContains('file_output', $lexicon['intents']);
        $this->assertSame('pdf', $lexicon['file_format']);

        $this->assertSame('skipped', $this->postJson(self::BASE.'/test', ['message' => 'شكرا'])->json('data.stage'));
        $this->assertSame('would_ask_model', $this->postJson(self::BASE.'/test', ['message' => 'حاجة غريبة بدون معنى واضح خالص'])->json('data.stage'));
    }
}
