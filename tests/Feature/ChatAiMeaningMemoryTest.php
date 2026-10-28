<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\AI\Models\AiMemory;
use Modules\AI\Models\AiPlan;
use Modules\AI\Models\AiProvider;
use Modules\AI\Repositories\AiProviderRepository;
use Modules\Chat\Models\ChatContact;
use Modules\Chat\Models\ChatMessage;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * DORR AI's search by meaning, memory, continue-in-another-chat and the AI-images gallery, inside
 * the ordinary chat — on the same AI system and plan as every chat AI tool.
 */
class ChatAiMeaningMemoryTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    private User $carol;

    private string $reply = 'Sure.';

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.chat.verification.enabled' => false]);
        $flag = Flag::create(['code' => 'sa']);
        $saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR', 'decimal_places' => 2])->id, 'status' => true]);
        Language::create(['code' => 'en', 'direction' => 'ltr', 'is_default_website' => true, 'is_default_dashboard' => true, 'stores_translation' => true, 'status' => true, 'flag_id' => $flag->id]);
        $make = fn (string $name, string $phone) => User::create(['name' => $name, 'phone' => $phone, 'country_id' => $saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->alice = $make('Alice', '+966500000001');
        $this->bob = $make('Bob', '+966500000002');
        $this->carol = $make('Carol', '+966500000003');
        foreach ([[$this->alice, $this->bob], [$this->bob, $this->alice], [$this->alice, $this->carol], [$this->carol, $this->alice]] as [$owner, $contact]) {
            ChatContact::query()->create(['owner_type' => 'user', 'owner_id' => $owner->id, 'name' => $contact->name, 'phone' => $contact->phone, 'contact_type' => 'user', 'contact_id' => $contact->id, 'source' => 'manual']);
        }

        AiPlan::query()->create(['name' => 'Trial', 'code' => 'trial', 'is_trial' => true, 'is_active' => true, 'sort_order' => 1, 'usage_minutes' => 600, 'cooldown_minutes' => 5]);
        app(AiProviderRepository::class)->ensureDefaults();
        AiProvider::query()->where('key', 'openai')->firstOrFail()->update(['is_enabled' => true, 'is_default' => true, 'api_key' => 'sk-test', 'model' => 'gpt-4o-mini']);

        Http::fake(['api.openai.com/v1/chat/completions' => function (Request $request) {
            $system = (string) ($request['messages'][0]['content'] ?? '');

            return Http::response(['choices' => [['message' => ['content' => match (true) {
                str_contains($system, 'search a chat by meaning') => json_encode(['الماتش', 'football']),
                str_contains($system, 'context capsule') => json_encode(['goal' => 'Plan the trip', 'decisions' => ['Leave on Friday'], 'facts' => ['Budget 3,000 SAR'], 'open_tasks' => ['Book the hotel'], 'done' => ['Rented a car']]),
                str_starts_with($system, 'You classify a request') => 'not json',
                default => $this->reply,
            }]]]]);
        }]);
    }

    public function test_a_chat_is_searched_by_meaning_only_in_what_i_can_see(): void
    {
        $chat = $this->direct($this->bob, $this->alice);
        $match = $this->send($this->bob, $chat, ['type' => 'text', 'body' => 'هنتفرج على الماتش بكرة؟'])->json('data.id');
        $this->send($this->bob, $chat, ['type' => 'text', 'body' => 'عايز أشتري عربية']);
        $other = $this->direct($this->carol, $this->alice);
        $this->send($this->carol, $other, ['type' => 'text', 'body' => 'football tonight'])->assertCreated();

        $this->as($this->alice);
        $result = $this->getJson("/api/mobile/v1/chat/conversations/{$chat}/ai/search?q=".urlencode('الكورة'), $this->headers())->assertOk();
        // «الكورة» never appears — its meaning found the match, and only in this chat.
        $this->assertSame(['الماتش', 'football'], $result->json('data.terms'));
        $this->assertSame([$match], array_column($result->json('data.messages'), 'id'));
        $this->assertSame('الماتش', $result->json("data.matches.{$match}"));

        // Someone outside the chat can't search it.
        $this->as($this->carol);
        $this->getJson("/api/mobile/v1/chat/conversations/{$chat}/ai/search?q=x1", $this->headers())->assertForbidden();
    }

    public function test_my_memory_reaches_the_chat_assistant_and_suggested_replies_and_remember_that_saves_it(): void
    {
        $chat = $this->direct($this->bob, $this->alice);
        $this->send($this->bob, $chat, ['type' => 'text', 'body' => 'نتغدى فين؟']);

        $this->as($this->alice);
        $this->postJson("/api/mobile/v1/chat/conversations/{$chat}/assistant", ['question' => 'افتكر إن أنا نباتي'], $this->headers())->assertOk();
        $this->assertSame(['أنا نباتي'], AiMemory::query()->where('owner_id', $this->alice->id)->pluck('content')->all());

        $this->postJson("/api/mobile/v1/chat/conversations/{$chat}/smart-replies", [], $this->headers())->assertOk();
        Http::assertSent(fn (Request $r) => str_contains((string) json_encode($r['messages'], JSON_UNESCAPED_UNICODE), 'You suggest replies')
            && collect($r['messages'])->contains(fn ($m) => $m['role'] === 'system' && str_contains($m['content'], '- أنا نباتي')));
    }

    public function test_a_chat_becomes_a_summary_i_edit_and_send_wherever_i_choose(): void
    {
        $chat = $this->direct($this->bob, $this->alice);
        $this->send($this->bob, $chat, ['type' => 'text', 'body' => 'We leave Friday, budget 3,000 SAR']);

        $this->as($this->alice);
        $result = $this->postJson("/api/mobile/v1/chat/conversations/{$chat}/ai/capsule", [], $this->headers())->assertOk()
            ->assertJsonPath('data.capsule.goal', 'Plan the trip')
            ->assertJsonPath('data.capsule.open_tasks.0', 'Book the hotel');
        $this->assertStringContainsString(__('chat.capsule.open_tasks'), $result->json('data.text'));
        // Nothing is sent by itself.
        $this->assertSame(1, ChatMessage::query()->count());
    }

    public function test_the_gallery_lists_the_ai_images_of_my_chats_only(): void
    {
        $chat = $this->direct($this->bob, $this->alice);
        $made = $this->send($this->bob, $chat, ['type' => 'image', 'files' => [\Illuminate\Http\UploadedFile::fake()->image('a.png', 20, 20)]])->json('data.id');
        $this->send($this->bob, $chat, ['type' => 'image', 'files' => [\Illuminate\Http\UploadedFile::fake()->image('b.png', 20, 20)]]);
        ChatMessage::query()->where('uuid', $made)->first()->forceFill(['meta' => ['ai_generated' => true]])->save();

        $this->as($this->alice);
        $this->getJson('/api/mobile/v1/chat/ai/images', $this->headers())->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $made)->assertJsonPath('data.0.conversation_id', $chat);

        $this->as($this->carol);
        $this->getJson('/api/mobile/v1/chat/ai/images', $this->headers())->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_these_need_the_dorr_ai_plan_too(): void
    {
        AiPlan::query()->delete();
        $chat = $this->direct($this->bob, $this->alice);
        $this->send($this->bob, $chat, ['type' => 'text', 'body' => 'hi']);

        $this->as($this->alice);
        $this->getJson("/api/mobile/v1/chat/conversations/{$chat}/ai/search?q=hello", $this->headers())->assertStatus(402)->assertJsonPath('error_code', 'chat_ai_plan_no_plan');
        $this->postJson("/api/mobile/v1/chat/conversations/{$chat}/ai/capsule", [], $this->headers())->assertStatus(402);
    }

    private function as(User $user): void
    {
        Sanctum::actingAs($user, [], 'user_api');
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['X-Country' => 'SA'];
    }

    private function direct(User $me, User $other): string
    {
        $this->as($me);

        return $this->postJson('/api/mobile/v1/chat/conversations/direct', ['participant_id' => $other->id], $this->headers())->assertOk()->json('data.id');
    }

    /** @param  array<string, mixed>  $data */
    private function send(User $sender, string $conversation, array $data): TestResponse
    {
        $this->as($sender);

        return $this->post("/api/mobile/v1/chat/conversations/{$conversation}/messages", $data, $this->headers() + ['Accept' => 'application/json']);
    }
}
