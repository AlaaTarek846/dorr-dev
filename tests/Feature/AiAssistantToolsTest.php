<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Modules\AI\Models\AiConversation;
use Modules\AI\Models\AiFeatureFlag;
use Modules\AI\Models\AiMemory;
use Modules\AI\Models\AiMessage;
use Modules\AI\Models\AiPlan;
use Modules\AI\Models\AiProject;
use Modules\AI\Models\AiProvider;
use Modules\AI\Repositories\AiProviderRepository;
use Modules\AI\Services\AiCitationValidator;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * DORR AI's own tools (spec 121–151, 314–332, 344): memory, "continue in a new chat" with its
 * Context Capsule, search by word and by meaning, projects, the outputs gallery, the evidence rules
 * before an answer and the citation checks after it, and the per-country service switch.
 */
class AiAssistantToolsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $other;

    /** What the fake model answers to an ordinary chat message. */
    private string $reply = 'Sure.';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('ai.chat.verification.enabled', false);
        $flag = Flag::create(['code' => 'sa']);
        $saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR', 'decimal_places' => 2])->id, 'status' => true]);
        Language::create(['code' => 'en', 'direction' => 'ltr', 'is_default_website' => true, 'is_default_dashboard' => true, 'stores_translation' => true, 'status' => true, 'flag_id' => $flag->id]);
        $this->user = User::create(['name' => 'Alice', 'phone' => '+966500000001', 'country_id' => $saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->other = User::create(['name' => 'Bob', 'phone' => '+966500000002', 'country_id' => $saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);

        AiPlan::query()->create(['name' => 'Trial', 'code' => 'trial-tools-test', 'is_trial' => true, 'is_active' => true, 'sort_order' => 1, 'usage_minutes' => 60, 'cooldown_minutes' => 5]);
        app(AiProviderRepository::class)->ensureDefaults();
        AiProvider::query()->where('key', 'openai')->firstOrFail()->update(['is_enabled' => true, 'is_default' => true, 'api_key' => 'sk-test', 'model' => 'gpt-4o-mini']);

        Http::fake(['api.openai.com/v1/chat/completions' => function (Request $request) {
            $system = (string) ($request['messages'][0]['content'] ?? '');

            return Http::response(['choices' => [['message' => ['content' => match (true) {
                str_contains($system, 'context capsule') => json_encode(['goal' => 'Plan the shop launch', 'decisions' => ['Open on 1 May'], 'facts' => ['Budget 20,000 SAR'], 'open_tasks' => ['Print the menu'], 'done' => ['Rented the place']]),
                str_contains($system, 'search a person\'s past conversations') => json_encode(['كرة القدم', 'الماتش', 'football']),
                str_starts_with($system, 'You classify a request') => 'not json',
                default => $this->reply,
            }]]]]);
        }]);
        Sanctum::actingAs($this->user, [], 'user_api');
    }

    // ------------------------------------------------------------------ memory (spec 140–144)

    public function test_memory_is_only_what_the_person_asked_for_and_they_control_it(): void
    {
        $id = $this->postJson('/api/user/v1/ai-chat/memories', ['content' => 'I am vegetarian'])->assertCreated()->json('data.id');
        $this->putJson("/api/user/v1/ai-chat/memories/{$id}", ['content' => 'I am vegan'])->assertOk()->assertJsonPath('data.content', 'I am vegan');
        $this->getJson('/api/user/v1/ai-chat/memories')->assertOk()->assertJsonPath('data.enabled', true)->assertJsonPath('data.memories.0.content', 'I am vegan');

        // Secrets are never kept, even when asked (spec 144).
        $this->postJson('/api/user/v1/ai-chat/memories', ['content' => 'my password is hunter22'])->assertUnprocessable()->assertJsonPath('error_code', 'ai_memory_secret_refused');
        $this->postJson('/api/user/v1/ai-chat/memories', ['content' => 'card 4111 1111 1111 1111'])->assertUnprocessable();

        // Someone else's memory is simply not there.
        Sanctum::actingAs($this->other, [], 'user_api');
        $this->deleteJson("/api/user/v1/ai-chat/memories/{$id}")->assertNotFound();
        $this->getJson('/api/user/v1/ai-chat/memories')->assertJsonCount(0, 'data.memories');

        // Off: nothing is saved.
        Sanctum::actingAs($this->user, [], 'user_api');
        $this->putJson('/api/user/v1/ai-chat/memories/settings', ['enabled' => false])->assertOk();
        $this->postJson('/api/user/v1/ai-chat/memories', ['content' => 'x y z'])->assertUnprocessable()->assertJsonPath('error_code', 'ai_memory_disabled');

        $this->deleteJson('/api/user/v1/ai-chat/memories')->assertOk()->assertJsonPath('data.deleted', 1);
    }

    public function test_remember_that_in_a_message_is_saved_and_every_later_answer_reads_it_while_memory_is_on(): void
    {
        $chat = $this->conversation();
        $this->send($chat, 'افتكر إن عيد ميلادي 5 مارس')->assertOk();

        $this->assertSame(['عيد ميلادي 5 مارس'], AiMemory::query()->pluck('content')->all());
        Http::assertSent(fn (Request $r) => $this->systemSays($r, 'It has been saved to their DORR AI memory'));

        // A later answer, in another conversation, knows it.
        $this->send($this->conversation(), 'What should I plan for my birthday?')->assertOk();
        Http::assertSent(fn (Request $r) => $this->systemSays($r, "What this user explicitly asked you to remember (use it only when it is relevant to their request; do not list it unprompted):\n- عيد ميلادي 5 مارس"));

        // Memory off: it is no longer sent to the model.
        $this->putJson('/api/user/v1/ai-chat/memories/settings', ['enabled' => false])->assertOk();
        Http::fake(['api.openai.com/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'ok']]]])]);
        $this->send($chat, 'And now?')->assertOk();
        Http::assertNotSent(fn (Request $r) => $this->systemSays($r, 'explicitly asked you to remember'));
    }

    // ------------------------------------------------------------------ continue in a new chat (spec 121–132)

    public function test_continue_in_a_new_chat_carries_a_capsule_the_person_can_read_and_delete(): void
    {
        $first = $this->conversation();
        $this->send($first, 'We will open the shop on 1 May with 20,000 SAR')->assertOk();
        $second = $this->conversation();
        $this->send($second, 'Remind me the menu is not printed yet')->assertOk();

        $response = $this->postJson('/api/user/v1/ai-chat/conversations/continue', ['conversation_ids' => [$first, $second]])->assertCreated()
            ->assertJsonPath('data.capsule.goal', 'Plan the shop launch')
            ->assertJsonPath('data.capsule.decisions.0', 'Open on 1 May')
            ->assertJsonPath('data.capsule.open_tasks.0', 'Print the menu')
            ->assertJsonCount(2, 'data.capsule.sources');
        $new = $response->json('data.conversation.id');

        // Only the two conversations picked were read (spec 132).
        Http::assertSent(fn (Request $r) => $this->systemSays($r, 'context capsule')
            && str_contains($r['messages'][1]['content'], '20,000 SAR') && str_contains($r['messages'][1]['content'], 'menu is not printed'));

        // Every answer in the new chat reads it.
        $this->send($new, 'What is left?')->assertOk();
        Http::assertSent(fn (Request $r) => $this->systemSays($r, "Goal: Plan the shop launch") && $this->systemSays($r, "Open tasks:\n- Print the menu"));

        $this->getJson("/api/user/v1/ai-chat/conversations/{$new}/capsule")->assertOk()->assertJsonPath('data.capsule.facts.0', 'Budget 20,000 SAR');
        $this->deleteJson("/api/user/v1/ai-chat/conversations/{$new}/capsule")->assertNoContent();
        $this->getJson("/api/user/v1/ai-chat/conversations/{$new}/capsule")->assertOk()->assertJsonPath('data.capsule', null);

        // Another person's conversation can't be a source.
        Sanctum::actingAs($this->other, [], 'user_api');
        $this->postJson('/api/user/v1/ai-chat/conversations/continue', ['conversation_ids' => [$first]])->assertNotFound();
    }

    public function test_without_a_plan_the_ai_tools_say_so_and_search_falls_back_to_words(): void
    {
        $chat = $this->conversation();
        $this->send($chat, 'هنتفرج على الماتش بتاع الأهلي')->assertOk();
        \Modules\AI\Models\AiTrialControl::query()->where('owner_id', $this->user->id)->update(['trial_status' => \Modules\AI\Models\AiTrialControl::TRIAL_ENDED]);
        \Modules\AI\Models\AiSubscription::query()->delete();
        Cache::flush();

        $this->postJson('/api/user/v1/ai-chat/conversations/continue', ['conversation_ids' => [$chat]])->assertStatus(402)->assertJsonPath('error_code', 'ai_plan_required');
        // Search still finds the words themselves — only the meaning needs the AI.
        $this->getJson('/api/user/v1/ai-chat/search?q='.urlencode('الأهلي'))->assertOk()->assertJsonPath('data.terms', [])->assertJsonPath('data.results.0.match', 'lexical');
    }

    // ------------------------------------------------------------------ search (spec 133–139)

    public function test_search_finds_by_word_and_by_meaning_with_highlight_snippet_date_and_message(): void
    {
        $chat = $this->conversation();
        $this->send($chat, 'هنتفرج على الماتش بتاع الأهلي بكرة')->assertOk();
        $message = AiMessage::query()->where('role', 'user')->latest('id')->first();

        // "الكورة" never appears — the meaning finds the match (spec 139).
        $hit = collect($this->getJson('/api/user/v1/ai-chat/search?q='.urlencode('الكورة'))->assertOk()->json('data.results'))->firstWhere('type', 'message');
        $this->assertSame(['semantic', 'الماتش', $message->id, $chat], [$hit['match'], $hit['term'], $hit['message_id'], $hit['conversation']['id']]);
        $this->assertSame('الماتش', mb_substr($hit['snippet'], $hit['highlights'][0]['start'], $hit['highlights'][0]['length']));
        $this->assertNotNull($hit['created_at']);

        // By word, with أ/ا written either way, highlighting the text as it was written.
        $byWord = collect($this->getJson('/api/user/v1/ai-chat/search?q='.urlencode('الاهلي'))->json('data.results'))->firstWhere('type', 'message');
        $this->assertSame('lexical', $byWord['match']);
        $this->assertSame('الأهلي', mb_substr($byWord['snippet'], $byWord['highlights'][0]['start'], $byWord['highlights'][0]['length']));

        // Titles and projects too; never someone else's chats.
        AiConversation::query()->whereKey($chat)->update(['title' => 'خطة الأهلي']);
        $project = AiProject::query()->create(['owner_type' => 'user', 'owner_id' => $this->user->id, 'name' => 'مشروع الأهلي', 'status' => 'active']);
        $project->projectConversations()->create(['conversation_id' => $chat]);
        $types = collect($this->getJson('/api/user/v1/ai-chat/search?q='.urlencode('الأهلي'))->json('data.results'))->pluck('type')->unique()->sort()->values()->all();
        $this->assertSame(['message', 'project', 'title'], $types);

        Sanctum::actingAs($this->other, [], 'user_api');
        Cache::flush();
        $this->getJson('/api/user/v1/ai-chat/search?q='.urlencode('الأهلي'))->assertOk()->assertJsonCount(0, 'data.results');
    }

    // ------------------------------------------------------------------ projects (spec 151, 315)

    public function test_a_projects_instructions_reach_every_answer_in_its_conversations(): void
    {
        $chat = $this->conversation();
        $project = $this->postJson('/api/user/v1/ai-chat/projects', ['name' => 'Coffee shop', 'description' => 'A small café in Jeddah', 'instructions' => 'Always answer with costs in SAR'])
            ->assertCreated()->assertJsonPath('data.instructions', 'Always answer with costs in SAR')->json('data.id');
        $this->postJson("/api/user/v1/ai-chat/projects/{$project}/conversations/{$chat}")->assertOk()->assertJsonPath('data.conversations.0.id', $chat);

        $this->send($chat, 'How much is a coffee machine?')->assertOk();
        Http::assertSent(fn (Request $r) => $this->systemSays($r, 'This conversation belongs to the user\'s project "Coffee shop".') && $this->systemSays($r, 'Project instruction from the user: Always answer with costs in SAR'));

        // Deleting the project keeps the conversation.
        $this->deleteJson("/api/user/v1/ai-chat/projects/{$project}")->assertNoContent();
        $this->assertTrue(AiConversation::query()->whereKey($chat)->exists());

        Sanctum::actingAs($this->other, [], 'user_api');
        $this->getJson('/api/user/v1/ai-chat/projects')->assertOk()->assertJsonCount(0, 'data');
    }

    // ------------------------------------------------------------------ outputs gallery (spec 344)

    public function test_the_outputs_gallery_lists_what_dorr_ai_made_not_what_the_person_uploaded(): void
    {
        $chat = $this->conversation();
        $conversation = AiConversation::query()->findOrFail($chat);
        $mine = $conversation->messages()->create(['role' => 'user', 'content' => 'draw', 'sequence_number' => 1]);
        $made = $conversation->messages()->create(['role' => 'assistant', 'content' => 'here', 'sequence_number' => 2, 'generated_file' => ['name' => 'plan.pdf', 'url' => 'https://x/plan.pdf']]);
        $conversation->attachments()->create(['message_id' => $mine->id, 'file_name' => 'me.jpg', 'file_path' => 'a/me.jpg', 'mime_type' => 'image/jpeg', 'file_size' => 10]);
        $conversation->attachments()->create(['message_id' => $made->id, 'file_name' => 'cat.png', 'file_path' => 'a/cat.png', 'mime_type' => 'image/png', 'file_size' => 10]);

        $items = collect($this->getJson('/api/user/v1/ai-chat/outputs')->assertOk()->json('data'));
        $this->assertEqualsCanonicalizing(['cat.png', 'plan.pdf'], $items->pluck('name')->all());
        $this->assertSame([$made->id], $items->pluck('message_id')->unique()->values()->all());
        $this->assertSame(['plan.pdf'], collect($this->getJson('/api/user/v1/ai-chat/outputs?kind=document')->json('data'))->pluck('name')->all());
    }

    // ------------------------------------------------------------------ evidence rules and citation checks (spec 5–19, 79–112, 275–283, 324, 332)

    public function test_a_legal_question_gets_the_evidence_rules_and_a_wrong_quran_reference_is_flagged(): void
    {
        $chat = $this->conversation();
        $this->send($chat, 'Can my landlord keep my deposit under the law?')->assertOk();
        Http::assertSent(fn (Request $r) => $this->systemSays($r, 'never claim to be 100% accurate') && $this->systemSays($r, 'Law: name the country, jurisdiction'));

        $this->reply = 'جاء في سورة البقرة آية 300 أن… والحساب: 12 × 3 = 40.';
        $this->send($chat, 'ما معنى الآية؟')->assertOk()
            ->assertJsonPath('data.warnings.0', __('ai.citation_quran_reference', ['ref' => 'سورة البقرة آية 300']))
            ->assertJsonPath('data.warnings.1', __('ai.citation_math', ['written' => '12 × 3 = 40', 'correct' => '36']));
        $this->assertNotEmpty(AiMessage::query()->where('role', 'assistant')->latest('id')->first()->verification_warnings);
    }

    public function test_the_citation_validator_checks_what_the_system_can_check_itself(): void
    {
        $validator = app(AiCitationValidator::class);

        // The surah table: 114 surahs, 6236 verses.
        $table = (new \ReflectionClassConstant(AiCitationValidator::class, 'SURAHS'))->getValue();
        $this->assertSame(114, count(array_unique(array_column($table, 0))));
        $this->assertSame(6236, array_sum(array_column(array_values(array_combine(array_column($table, 0), $table)), 1)));

        $this->assertSame([], $validator->badQuranReferences('قال تعالى في سورة البقرة آية 255، وسورة آل عمران الآية 200، وسورة المائدة آية ٣، والقرآن 2:286.'));
        $this->assertSame(['سورة البقرة آية 300', 'سورة الفلاح آية 3', 'القرآن 2:287', 'سورة 115:1'], $validator->badQuranReferences('سورة البقرة آية 300، وسورة الفلاح آية 3، والقرآن 2:287، وسورة 115:1.'));

        $this->assertSame([['1,500 + 2,000 = 3,600', '3,500'], ['15% من 200 = 40', '30']], $validator->wrongArithmetic('1,500 + 2,000 = 3,600 و 15% من 200 = 40 و 7 × 6 = 42 و 10/4 = 2.5'));
        $this->assertSame(['2026-02-30', '31/02/2026'], $validator->impossibleDates('2026-02-30 و 31/02/2026 و 12/31/2026 و 2026-12-31'));

        // Source markers past the last source go; with no sources nothing is touched.
        $this->assertSame(['text' => 'A [1] and B.', 'warnings' => [__('ai.citation_unknown_source', ['refs' => '[3]'])]], $validator->validate('A [1] and B [3].', 'q', 2) + []);
        $this->assertSame('A [3].', $validator->validate('A [3].', 'q', 0)['text']);

        // A dream interpretation in Arabic closes with «والله أعلم» (spec 97); a legal answer with no country is flagged (spec 79).
        $this->assertStringEndsWith('والله أعلم.', $validator->validate('الحلم ده بيدل على خير.', 'حلمت إني بطير')['text']);
        $this->assertContains(__('ai.citation_jurisdiction'), $validator->validate('You can sue him.', 'My landlord kept my deposit, can I sue him?')['warnings']);
        $this->assertNotContains(__('ai.citation_jurisdiction'), $validator->validate('In Saudi Arabia you can sue him.', 'My landlord kept my deposit, can I sue him?')['warnings']);
    }

    // ------------------------------------------------------------------ service flag (requirement 7–8)

    public function test_the_admin_can_switch_the_dorr_ai_chat_off_for_a_country_without_deleting_anything(): void
    {
        $chat = $this->conversation();
        // The home's plan chip: read-only, so asking starts no trial.
        \Modules\AI\Models\AiSubscription::query()->delete();
        $this->getJson('/api/user/v1/ai-chat/status')->assertOk()->assertJsonPath('data.available', true)
            ->assertJsonPath('data.plan.allowed', true)->assertJsonPath('data.plan.plan_name', 'Trial')->assertJsonPath('data.plan.is_trial', true);
        $this->assertSame(0, \Modules\AI\Models\AiSubscription::query()->count());

        AiFeatureFlag::query()->create(['key' => 'ai-chat-off-sa', 'target_type' => AiFeatureFlag::TARGET_SERVICE, 'tool_key' => AiFeatureFlag::SERVICE_AI_CHAT, 'country_code' => 'SA', 'environment' => config('app.env'), 'is_enabled' => false]);
        Cache::flush();

        $this->getJson('/api/user/v1/ai-chat/status')->assertOk()->assertJsonPath('data.available', false);
        $this->send($chat, 'hello')->assertForbidden()->assertJsonPath('message', __('ai.assistant_service_unavailable'));
        // The conversation is still there.
        $this->getJson("/api/user/v1/ai-chat/conversations/{$chat}")->assertOk();
    }

    private function conversation(): int
    {
        return $this->postJson('/api/user/v1/ai-chat/conversations')->assertCreated()->json('data.id');
    }

    private function send(int $conversation, string $message): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/user/v1/ai-chat/conversations/{$conversation}/messages", ['message' => $message]);
    }

    /** One of the request's system messages contains the text. */
    private function systemSays(Request $request, string $text): bool
    {
        return collect($request['messages'] ?? [])->contains(fn ($m) => ($m['role'] ?? null) === 'system' && is_string($m['content'] ?? null) && str_contains($m['content'], $text));
    }
}
