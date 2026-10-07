<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\AI\Models\AiProvider;
use Modules\AI\Repositories\AiProviderRepository;
use Modules\Chat\Models\ChatContact;
use Modules\Chat\Models\ChatSetting;
use Modules\Chat\Services\ChatAiService;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * AI in the chat: translate, voice to text, summary, suggested replies — each only on a tap, only
 * on what the person can see, never a view-once message.
 */
class ChatAiTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    private User $carol;

    /** What the fake OpenAI answers to a chat request. */
    private string $reply = 'Good morning';

    /** And with which status (401 = a rejected key). */
    private int $status = 200;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $sar = Currency::create(['code' => 'SAR', 'symbol' => 'SAR', 'decimal_places' => 2]);
        $flag = Flag::create(['code' => 'sa']);
        $saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => $sar->id, 'status' => true]);
        Language::create(['code' => 'en', 'direction' => 'ltr', 'is_default_website' => true, 'is_default_dashboard' => true, 'stores_translation' => true, 'status' => true, 'flag_id' => $flag->id]);

        $make = fn (string $name, string $phone) => User::create(['name' => $name, 'phone' => $phone, 'country_id' => $saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->alice = $make('Alice', '+966500000001');
        $this->bob = $make('Bob', '+966500000002');
        $this->carol = $make('Carol', '+966500000003');

        foreach ([[$this->alice, $this->bob], [$this->bob, $this->alice]] as [$owner, $contact]) {
            ChatContact::query()->create(['owner_type' => 'user', 'owner_id' => $owner->id, 'name' => $contact->name, 'phone' => $contact->phone, 'contact_type' => 'user', 'contact_id' => $contact->id, 'source' => 'manual']);
        }

        app(AiProviderRepository::class)->ensureDefaults();
        $this->provider('openai', ['is_enabled' => true, 'is_default' => true, 'api_key' => 'sk-test', 'model' => 'gpt-4o-mini']);

        Http::fake([
            'api.openai.com/v1/chat/completions' => fn () => $this->status === 200
                ? Http::response(['choices' => [['message' => ['content' => $this->reply]]]])
                : Http::response(['error' => ['message' => 'Incorrect API key sk-test']], $this->status),
            'api.openai.com/v1/audio/transcriptions' => Http::response(['text' => 'See you at the station at five']),
        ]);
    }

    public function test_the_app_learns_what_it_can_offer(): void
    {
        $this->as($this->alice);
        $this->getJson('/api/mobile/v1/chat/ai', $this->headers())->assertOk()
            ->assertJsonPath('data.translate', true)->assertJsonPath('data.transcribe', true);

        // Anthropic chats but takes no audio.
        $this->provider('openai', ['is_enabled' => false, 'is_default' => false]);
        $this->provider('anthropic', ['is_enabled' => true, 'is_default' => true, 'api_key' => 'sk-ant']);
        $this->getJson('/api/mobile/v1/chat/ai', $this->headers())->assertOk()
            ->assertJsonPath('data.summarize', true)->assertJsonPath('data.transcribe', false);

        // The admin's switch turns everything off.
        ChatSetting::current()->update(['ai_enabled' => false]);
        $this->getJson('/api/mobile/v1/chat/ai', $this->headers())->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.translate', false);
    }

    public function test_a_message_is_translated_once_and_then_from_cache(): void
    {
        $chat = $this->direct($this->bob, $this->alice);
        $message = $this->send($this->bob, $chat, ['type' => 'text', 'body' => 'صباح *الخير*'])->json('data.id');

        $this->as($this->alice);
        $this->postJson("/api/mobile/v1/chat/messages/{$message}/translate", ['to' => 'en'], $this->headers())
            ->assertOk()->assertJsonPath('data.text', 'Good morning')->assertJsonPath('data.to', 'en');
        $this->postJson("/api/mobile/v1/chat/messages/{$message}/translate", ['to' => 'en'], $this->headers())->assertOk();

        // One call to the provider, with the text (formatting marks removed) and the language.
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'chat/completions')
            && $r['messages'][1]['content'] === 'صباح الخير' && str_contains($r['messages'][0]['content'], 'English'));

        $this->postJson("/api/mobile/v1/chat/messages/{$message}/translate", ['to' => 'xx'], $this->headers())->assertUnprocessable();
    }

    public function test_only_people_in_the_chat_and_never_view_once(): void
    {
        $chat = $this->direct($this->bob, $this->alice);
        $text = $this->send($this->bob, $chat, ['type' => 'text', 'body' => 'private'])->json('data.id');

        $this->as($this->carol);
        $this->postJson("/api/mobile/v1/chat/messages/{$text}/translate", [], $this->headers())->assertForbidden();

        $once = $this->send($this->bob, $chat, [
            'type' => 'image', 'body' => 'just once', 'view_once' => true, 'files' => [UploadedFile::fake()->image('p.jpg', 200, 200)],
        ])->assertCreated()->json('data.id');
        $this->as($this->alice);
        $this->postJson("/api/mobile/v1/chat/messages/{$once}/translate", [], $this->headers())
            ->assertUnprocessable()->assertJsonPath('error_code', 'chat_ai_view_once');

        Http::assertNothingSent();
    }

    public function test_a_voice_message_becomes_text(): void
    {
        $chat = $this->direct($this->bob, $this->alice);
        $voice = $this->send($this->bob, $chat, [
            'type' => 'voice', 'duration_ms' => 3000, 'files' => [UploadedFile::fake()->create('note.m4a', 40, 'audio/mp4')],
        ])->assertCreated()->json('data.id');
        $text = $this->send($this->bob, $chat, ['type' => 'text', 'body' => 'hi'])->json('data.id');

        $this->as($this->alice);
        $this->postJson("/api/mobile/v1/chat/messages/{$voice}/transcribe", [], $this->headers())
            ->assertOk()->assertJsonPath('data.text', 'See you at the station at five');
        $this->postJson("/api/mobile/v1/chat/messages/{$text}/transcribe", [], $this->headers())
            ->assertUnprocessable()->assertJsonPath('error_code', 'chat_ai_not_voice');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'audio/transcriptions'));
    }

    public function test_a_chat_is_summarised_from_what_i_can_see(): void
    {
        $chat = $this->direct($this->bob, $this->alice);
        $this->send($this->bob, $chat, ['type' => 'text', 'body' => 'Dinner Friday at 8?'])->assertCreated();
        $this->send($this->alice, $chat, ['type' => 'text', 'body' => 'Yes, I will book the table'])->assertCreated();
        $this->send($this->bob, $chat, ['type' => 'image', 'body' => 'secret', 'view_once' => true, 'files' => [UploadedFile::fake()->image('s.jpg', 100, 100)]])->assertCreated();
        $this->reply = "- Dinner on Friday at 8\n- Alice books the table";

        $this->as($this->alice);
        $this->postJson("/api/mobile/v1/chat/conversations/{$chat}/summarize", [], $this->headers())
            ->assertOk()->assertJsonPath('data.text', $this->reply)->assertJsonPath('data.messages', 2);

        Http::assertSent(function (Request $r) {
            $chat = $r['messages'][1]['content'];

            return str_contains($chat, 'Bob: Dinner Friday at 8?') && str_contains($chat, 'Me: Yes, I will book the table') && ! str_contains($chat, 'secret');
        });
    }

    public function test_suggested_replies_are_three_short_lines(): void
    {
        $chat = $this->direct($this->bob, $this->alice);
        $this->send($this->bob, $chat, ['type' => 'text', 'body' => 'هتيجي النهارده؟'])->assertCreated();
        $this->reply = 'Sure: ["أكيد جاي", "مش هقدر النهارده", "هكلمك كمان شوية"]';

        $this->as($this->alice);
        $this->postJson("/api/mobile/v1/chat/conversations/{$chat}/smart-replies", [], $this->headers())
            ->assertOk()->assertJsonPath('data.replies', ['أكيد جاي', 'مش هقدر النهارده', 'هكلمك كمان شوية']);

        // A model that ignores the format still gives usable lines.
        $this->assertSame(['Yes', 'Maybe later', 'No thanks'], ChatAiService::parseReplies("1. Yes\n2. Maybe later\n- No thanks\n"));
    }

    public function test_no_provider_or_a_failing_one_says_so_plainly(): void
    {
        $chat = $this->direct($this->bob, $this->alice);
        $message = $this->send($this->bob, $chat, ['type' => 'text', 'body' => 'hello'])->json('data.id');

        $this->status = 401;
        $this->as($this->alice);
        $this->postJson("/api/mobile/v1/chat/messages/{$message}/translate", ['to' => 'ar'], $this->headers())
            ->assertStatus(502)->assertJsonPath('error_code', 'chat_ai_failed')
            ->assertJsonMissing(['message' => 'Incorrect API key sk-test']);

        AiProvider::query()->update(['is_enabled' => false]);
        $this->postJson("/api/mobile/v1/chat/messages/{$message}/translate", ['to' => 'fr'], $this->headers())
            ->assertStatus(503)->assertJsonPath('error_code', 'chat_ai_unavailable');
    }

    // ================================================================ helpers

    /**
     * Through the model: the API key is stored encrypted.
     *
     * @param  array<string, mixed>  $data
     */
    public function test_a_question_about_the_messages_i_picked_answers_with_references_and_sends_only_those(): void
    {
        $chat = $this->direct($this->bob, $this->alice);
        $this->send($this->bob, $chat, ['type' => 'text', 'body' => 'My salary is private'])->assertCreated();
        $where = $this->send($this->bob, $chat, ['type' => 'text', 'body' => 'Meet at the station'])->json('data.id');
        $when = $this->send($this->bob, $chat, ['type' => 'text', 'body' => 'Friday at five'])->json('data.id');

        $this->reply = '{"answer": "At the station on Friday at five.", "refs": [1, 2]}';
        $this->as($this->alice);
        $this->postJson("/api/mobile/v1/chat/conversations/{$chat}/ask", ['question' => 'Where and when do we meet?', 'messages' => [$where, $when]], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.answer', 'At the station on Friday at five.')
            ->assertJsonPath('data.references.0.id', $where)
            ->assertJsonPath('data.references.1.id', $when)
            ->assertJsonPath('data.messages', 2);

        // AT-PRIV-11: only the picked messages went to the AI.
        Http::assertSent(fn ($r) => str_contains($r->url(), 'chat/completions') && str_contains(json_encode($r->data()), 'Meet at the station'));
        Http::assertNotSent(fn ($r) => str_contains(json_encode($r->data()), 'salary'));
    }

    public function test_commitments_are_suggestions_with_their_time_and_nothing_is_set_on_its_own(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-06 09:00', 'UTC'));
        $chat = $this->direct($this->bob, $this->alice);
        $promise = $this->send($this->alice, $chat, ['type' => 'text', 'body' => 'I will send you the contract on Thursday at 5 pm'])->json('data.id');

        $this->reply = '```json'."\n".'[{"text": "Send Bob the contract", "owner": "me", "due": "2026-10-08 17:00", "ref": 1}]'."\n".'```';
        $this->as($this->alice);
        $this->postJson("/api/mobile/v1/chat/conversations/{$chat}/commitments", ['timezone' => 'Asia/Riyadh'], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.commitments.0.text', 'Send Bob the contract')
            ->assertJsonPath('data.commitments.0.is_mine', true)
            ->assertJsonPath('data.commitments.0.message_id', $promise)
            // 17:00 in Riyadh is 14:00 UTC.
            ->assertJsonPath('data.commitments.0.due_at', '2026-10-08T14:00:00+00:00');

        $this->assertDatabaseCount('chat_message_reminders', 0);
        $this->getJson('/api/mobile/v1/chat/ai', $this->headers())->assertJsonPath('data.ask', true)->assertJsonPath('data.commitments', true);
    }

    private function provider(string $key, array $data): void
    {
        AiProvider::query()->where('key', $key)->firstOrFail()->update($data);
    }

    private function as(User $user): void
    {
        Sanctum::actingAs($user, [], 'user_api');
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return ['X-Country' => 'SA'];
    }

    private function direct(User $me, User $other): string
    {
        $this->as($me);

        return $this->postJson('/api/mobile/v1/chat/conversations/direct', ['participant_id' => $other->id], $this->headers())->assertOk()->json('data.id');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function send(User $sender, string $conversation, array $data): \Illuminate\Testing\TestResponse
    {
        $this->as($sender);

        return $this->post("/api/mobile/v1/chat/conversations/{$conversation}/messages", $data, $this->headers() + ['Accept' => 'application/json']);
    }
}
