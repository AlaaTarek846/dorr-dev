<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\AI\Models\AiLearnedIntent;
use Modules\AI\Services\AiLearnedIntentAdminService;
use Tests\TestCase;

/**
 * The admin side of the learned dictionary: creating, switching on/off,
 * deleting, listing, stats and the side-effect-free "try a phrase" panel.
 */
class AiLearnedIntentAdminServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // phpunit.xml switches the router off for every other test; the "try a phrase" preview reports its state.
        config(['ai.intent_router.enabled' => true]);
    }

    protected function service(): AiLearnedIntentAdminService
    {
        return app(AiLearnedIntentAdminService::class);
    }

    /** @return array<string, mixed> */
    protected function payload($response): array
    {
        return json_decode($response->getContent(), true);
    }

    public function test_an_admin_phrase_is_normalized_and_active_immediately(): void
    {
        $response = $this->service()->create(['phrase' => 'نَفسى اشوف', 'match_mode' => 'phrase', 'intent' => 'image_generation']);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertDatabaseHas('ai_learned_intents', [
            'phrase' => 'نفسي اشوف', 'intent' => 'image_generation', 'is_active' => true, 'source' => 'admin', 'conflicts' => 0,
        ]);
    }

    public function test_the_file_format_is_only_kept_for_file_output(): void
    {
        $this->service()->create(['phrase' => 'جهزهالي كشيت', 'match_mode' => 'phrase', 'intent' => 'file_output', 'file_format' => 'xlsx']);
        $this->service()->create(['phrase' => 'قولهالي بصوتك', 'match_mode' => 'phrase', 'intent' => 'voice_reply', 'file_format' => 'pdf']);

        $this->assertSame('xlsx', AiLearnedIntent::query()->where('intent', 'file_output')->value('file_format'));
        $this->assertNull(AiLearnedIntent::query()->where('intent', 'voice_reply')->value('file_format'));
    }

    public function test_switching_off_marks_it_untrusted_and_switching_on_clears_that(): void
    {
        $row = AiLearnedIntent::query()->create([
            'phrase' => 'نفسي اشوف', 'match_mode' => 'phrase', 'intent' => 'image_generation', 'language' => 'ar',
            'confidence' => 0.95, 'confirmations' => 2, 'is_active' => true,
        ]);

        $this->service()->update($row->id, ['is_active' => false]);
        $row->refresh();
        $this->assertFalse($row->is_active);
        $this->assertGreaterThan(0, $row->conflicts, 'the router must never switch it back on by itself');

        $this->service()->update($row->id, ['is_active' => true]);
        $row->refresh();
        $this->assertTrue($row->is_active);
        $this->assertSame(0, $row->conflicts);
    }

    public function test_changing_the_intent_to_one_that_already_exists_is_rejected(): void
    {
        $a = AiLearnedIntent::query()->create(['phrase' => 'وريني منظر', 'match_mode' => 'phrase', 'intent' => 'image_generation', 'language' => 'ar', 'confidence' => 1, 'is_active' => true]);
        AiLearnedIntent::query()->create(['phrase' => 'وريني منظر', 'match_mode' => 'phrase', 'intent' => 'web_search', 'language' => 'ar', 'confidence' => 1, 'is_active' => true]);

        $this->expectException(ValidationException::class);

        $this->service()->update($a->id, ['intent' => 'web_search']);
    }

    public function test_delete_stats_and_filters(): void
    {
        $active = AiLearnedIntent::query()->create(['phrase' => 'نفسي اشوف', 'match_mode' => 'phrase', 'intent' => 'image_generation', 'language' => 'ar', 'confidence' => 1, 'is_active' => true, 'hits' => 5]);
        AiLearnedIntent::query()->create(['phrase' => 'ممكن تسمعني', 'match_mode' => 'phrase', 'intent' => 'voice_reply', 'language' => 'ar', 'confidence' => 0.9, 'is_active' => false]);
        AiLearnedIntent::query()->create(['phrase' => 'وريني منظر', 'match_mode' => 'phrase', 'intent' => 'web_search', 'language' => 'ar', 'confidence' => 0.9, 'is_active' => false, 'conflicts' => 1]);

        $stats = $this->payload($this->service()->stats())['data'];
        $this->assertSame(3, $stats['total']);
        $this->assertSame(1, $stats['active']);
        $this->assertSame(1, $stats['pending']);
        $this->assertSame(1, $stats['conflicts']);
        $this->assertSame(5, $stats['hits']);

        $this->assertCount(1, $this->payload($this->service()->list(['status' => 'conflict']))['data']);
        $this->assertCount(1, $this->payload($this->service()->list(['status' => 'pending']))['data']);
        $this->assertCount(1, $this->payload($this->service()->list(['intent' => 'image_generation']))['data']);
        $this->assertCount(1, $this->payload($this->service()->list(['search' => 'نَفسى']))['data'], 'search normalizes spelling too');

        $this->service()->delete($active->id);
        $this->assertDatabaseMissing('ai_learned_intents', ['id' => $active->id]);
    }

    public function test_try_a_phrase_has_no_side_effects(): void
    {
        $row = AiLearnedIntent::query()->create(['phrase' => 'نفسي اشوف', 'match_mode' => 'phrase', 'intent' => 'image_generation', 'language' => 'ar', 'confidence' => 1, 'is_active' => true]);

        $learned = $this->payload($this->service()->test('نفسي اشوف بيت على البحر', false))['data'];
        $this->assertSame('learned', $learned['stage']);
        $this->assertSame(['image_generation'], $learned['intents']);
        $this->assertSame(0, $row->fresh()->hits, 'testing must not count as a use');

        $lexicon = $this->payload($this->service()->test('اعمل ملف pdf عن مصر', false))['data'];
        $this->assertSame('lexicon', $lexicon['stage']);
        $this->assertContains('file_output', $lexicon['intents']);
        $this->assertSame('pdf', $lexicon['file_format']);

        $this->assertSame('skipped', $this->payload($this->service()->test('شكرا', false))['data']['stage']);
        $this->assertSame('would_ask_model', $this->payload($this->service()->test('حاجة غريبة بدون معنى واضح خالص', false))['data']['stage']);
    }
}
