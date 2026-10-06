<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use App\Models\NotificationDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\AI\Models\AiProvider;
use Modules\AI\Repositories\AiProviderRepository;
use Modules\Chat\Models\ChatContact;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatParticipant;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * DORR AI tools in the chat (spec 31, 36–42, 46, 48, 49) and my tasks (38): one tap each, only
 * what that tap covers goes to the AI, everything is a suggestion until confirmed — and the
 * DORR AI safety rules apply.
 */
class ChatAiToolsTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    private User $carol;

    private string $reply = 'ok';

    private string $classification = '{"domain": "none", "specific": false}';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
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

        app(AiProviderRepository::class)->ensureDefaults();
        AiProvider::query()->where('key', 'openai')->firstOrFail()->update(['is_enabled' => true, 'is_default' => true, 'api_key' => 'sk-test', 'model' => 'gpt-4o-mini']);
        config(['services.onesignal.app_id' => 'app', 'services.onesignal.rest_api_key' => 'rest']);
        Http::fake([
            'api.openai.com/v1/chat/completions' => function ($request) {
                $system = (string) ($request['messages'][0]['content'] ?? '');

                return Http::response(['choices' => [['message' => ['content' => str_starts_with($system, 'You classify a request') ? $this->classification : $this->reply]]]]);
            },
            'api.onesignal.com/*' => Http::response(['id' => 'n1']),
        ]);
    }

    public function test_the_assistant_answers_only_me_reads_only_what_i_picked_and_keeps_the_safety_rules(): void
    {
        $chat = $this->direct($this->alice, $this->bob);
        $this->send($this->bob, $chat, ['type' => 'text', 'body' => 'My bank PIN is 4321'])->assertCreated();
        $picked = $this->send($this->bob, $chat, ['type' => 'text', 'body' => 'The flight lands at 6 pm'])->json('data.id');

        // A general question: nothing of the chat goes.
        $this->reply = '{"answer": "Paris is the capital of France.", "refs": []}';
        $this->as($this->alice);
        $this->postJson("/api/mobile/v1/chat/conversations/{$chat}/assistant", ['question' => 'What is the capital of France?'], $this->headers())
            ->assertOk()->assertJsonPath('data.answer', 'Paris is the capital of France.')->assertJsonPath('data.safety', null);
        Http::assertNotSent(fn ($r) => str_contains(json_encode($r->data()), 'flight') || str_contains(json_encode($r->data()), 'PIN is'));

        // About a message I picked: only that one, with a reference back to it.
        $this->reply = '{"answer": "It lands at 6 pm.", "refs": [1]}';
        $this->postJson("/api/mobile/v1/chat/conversations/{$chat}/assistant", ['question' => 'When does it land?', 'messages' => [$picked]], $this->headers())
            ->assertOk()->assertJsonPath('data.references.0.id', $picked);
        Http::assertNotSent(fn ($r) => str_contains(json_encode($r->data()), 'PIN is'));

        // A personal medical question: the approved referral, added by the system.
        $this->classification = '{"domain": "medicine", "specific": true}';
        $this->reply = '{"answer": "Rest and drink water; a fever can have many causes.", "refs": []}';
        $this->postJson("/api/mobile/v1/chat/conversations/{$chat}/assistant", ['question' => 'My son has a fever of 39, what medicine do I give him?'], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.safety.domain', 'medicine')
            ->assertJsonPath('data.safety.notice', __('ai.safety.notice.medicine'));
    }

    public function test_proofreading_fixes_only_the_text_i_am_writing(): void
    {
        $this->reply = 'I will come tomorrow, inshallah 🙂';
        $this->as($this->alice);
        $this->postJson('/api/mobile/v1/chat/ai/proofread', ['text' => 'I wil come tomorow, inshallah 🙂'], $this->headers())
            ->assertOk()->assertJsonPath('data.text', 'I will come tomorrow, inshallah 🙂')->assertJsonPath('data.changed', true);
        $this->getJson('/api/mobile/v1/chat/ai', $this->headers())->assertJsonPath('data.proofread', true)->assertJsonPath('data.today', true);
    }

    public function test_understanding_and_simplifying_a_message_never_a_view_once_one(): void
    {
        $chat = $this->direct($this->alice, $this->bob);
        $long = $this->send($this->bob, $chat, ['type' => 'text', 'body' => 'Can you please send me the signed contract before Thursday? The client is getting impatient and we might lose the deal.'])->json('data.id');

        $this->reply = '{"intent": "request", "tone": "worried", "summary": "Bob wants the signed contract before Thursday.", "tone_note": "He is under pressure; reassure him.", "actions": ["reply", "remind", "dance"], "replies": ["Sending it today", "On it!", "Will do by Wednesday"]}';
        $this->as($this->alice);
        $this->postJson("/api/mobile/v1/chat/messages/{$long}/understand", [], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.intent', 'request')
            ->assertJsonPath('data.tone', 'worried')
            ->assertJsonPath('data.actions', ['reply', 'remind'])
            ->assertJsonCount(3, 'data.replies');

        $this->reply = '{"short": "Send the signed contract before Thursday.", "points": ["Signed contract", "Before Thursday", "Client is impatient"]}';
        $this->postJson("/api/mobile/v1/chat/messages/{$long}/simplify", [], $this->headers())
            ->assertOk()->assertJsonCount(3, 'data.points')->assertJsonPath('data.short', 'Send the signed contract before Thursday.');

        $once = $this->send($this->bob, $chat, ['type' => 'image', 'view_once' => true, 'files' => [UploadedFile::fake()->image('p.jpg')]])->json('data.id');
        $this->as($this->alice);
        $this->postJson("/api/mobile/v1/chat/messages/{$once}/understand", [], $this->headers())->assertStatus(422);
    }

    public function test_tasks_from_a_message_are_saved_only_when_i_say_and_remind_me_once(): void
    {
        $this->travelTo(Carbon::parse('2026-10-08 08:00', 'UTC'));
        NotificationDevice::query()->create(['owner_type' => User::class, 'owner_id' => $this->alice->id, 'player_id' => 'alice-phone', 'platform' => 'android']);
        $chat = $this->direct($this->alice, $this->bob);
        $message = $this->send($this->bob, $chat, ['type' => 'text', 'body' => 'Buy bread, and call the plumber tomorrow at 10'])->json('data.id');

        $this->reply = '[{"text": "Buy bread", "due": null}, {"text": "Call the plumber", "due": "2026-10-09 10:00"}]';
        $this->as($this->alice);
        $suggested = $this->postJson("/api/mobile/v1/chat/messages/{$message}/tasks", ['timezone' => 'Asia/Riyadh'], $this->headers())
            ->assertOk()->assertJsonPath('data.tasks.1.due_at', '2026-10-09T07:00:00+00:00')->json('data.tasks');
        $this->assertDatabaseCount('chat_tasks', 0);

        $this->postJson('/api/mobile/v1/chat/tasks', ['tasks' => $suggested, 'message_id' => $message], $this->headers())->assertCreated()->assertJsonPath('data.1.message_id', $message);
        $this->getJson('/api/mobile/v1/chat/tasks', $this->headers())->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.text', 'Call the plumber');
        $this->as($this->bob);
        $this->getJson('/api/mobile/v1/chat/tasks', $this->headers())->assertJsonCount(0, 'data');

        // Due: one notification, then never again.
        $this->travelTo(Carbon::parse('2026-10-09 07:01', 'UTC'));
        $this->artisan('chat:task-reminders')->assertSuccessful();
        $this->artisan('chat:task-reminders')->assertSuccessful();
        app(\Illuminate\Support\Defer\DeferredCallbackCollection::class)->invoke();
        $this->assertCount(1, collect(Http::recorded())->filter(fn ($pair) => ($pair[0]['data']['event'] ?? null) === 'chat.task.due'));

        $this->as($this->alice);
        $plumber = $this->getJson('/api/mobile/v1/chat/tasks', $this->headers())->json('data.0.id');
        $this->patchJson("/api/mobile/v1/chat/tasks/{$plumber}", ['done' => true], $this->headers())->assertOk()->assertJsonPath('data.done', true);
        $this->getJson('/api/mobile/v1/chat/tasks?status=done', $this->headers())->assertJsonCount(1, 'data');
        $this->deleteJson("/api/mobile/v1/chat/tasks/{$plumber}", [], $this->headers())->assertOk();
    }

    public function test_a_note_goes_to_my_notes_and_dates_are_only_suggestions(): void
    {
        $this->travelTo(Carbon::parse('2026-10-08 08:00', 'UTC'));
        $chat = $this->direct($this->alice, $this->bob);
        $address = $this->send($this->bob, $chat, ['type' => 'text', 'body' => 'The clinic is at 12 King Road, appointment Sunday 4 pm, bring the file'])->json('data.id');

        $this->reply = '{"title": "Clinic visit", "points": ["12 King Road", "Sunday 4 pm", "Bring the file"]}';
        $this->as($this->alice);
        $note = $this->postJson("/api/mobile/v1/chat/conversations/{$chat}/note", ['messages' => [$address]], $this->headers())
            ->assertCreated()->assertJsonPath('data.title', 'Clinic visit')->json('data.message');
        $self = $this->postJson('/api/mobile/v1/chat/conversations/self', [], $this->headers())->json('data.id');
        $this->assertSame($self, $note['conversation_id']);
        $this->assertStringContainsString('• 12 King Road', $note['body']);

        $this->reply = '[{"title": "Clinic appointment", "at": "2026-10-11 16:00", "all_day": false, "ref": 1}, {"title": "Invented", "at": "not a date", "ref": 1}]';
        $this->postJson("/api/mobile/v1/chat/conversations/{$chat}/dates", ['timezone' => 'Asia/Riyadh'], $this->headers())
            ->assertOk()
            ->assertJsonCount(1, 'data.dates')
            ->assertJsonPath('data.dates.0.at', '2026-10-11T13:00:00+00:00')
            ->assertJsonPath('data.dates.0.message_id', $address);
        $this->assertDatabaseCount('chat_message_reminders', 0);
    }

    public function test_important_and_today_read_my_chats_but_never_locked_chats_or_sensitive_messages(): void
    {
        $withBob = $this->direct($this->alice, $this->bob);
        $ask = $this->send($this->bob, $withBob, ['type' => 'text', 'body' => 'Can you transfer the rent today?'])->json('data.id');
        $this->send($this->bob, $withBob, ['type' => 'text', 'body' => 'my sensitive diagnosis', 'sensitive' => true])->assertCreated();
        $withCarol = $this->direct($this->alice, $this->carol);
        $this->send($this->carol, $withCarol, ['type' => 'text', 'body' => 'a locked chat secret'])->assertCreated();
        // Alice locked her chat with Carol.
        ChatParticipant::query()->where('conversation_id', ChatConversation::query()->where('uuid', $withCarol)->value('id'))
            ->where('participant_id', $this->alice->id)->update(['is_locked' => true]);

        $this->reply = '[{"ref": 1, "level": "high", "why": "Money request for today"}]';
        $this->as($this->alice);
        $this->postJson('/api/mobile/v1/chat/ai/important', [], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.items.0.message_id', $ask)
            ->assertJsonPath('data.items.0.conversation_id', $withBob)
            ->assertJsonPath('data.items.0.chat', 'Bob')
            ->assertJsonPath('data.items.0.level', 'high')
            ->assertJsonPath('data.chats', 1);

        $this->reply = '{"summary": ["Bob asked for the rent today."], "highlights": [{"ref": 1, "text": "Rent due today"}]}';
        $this->postJson('/api/mobile/v1/chat/ai/today', ['timezone' => 'Asia/Riyadh'], $this->headers())
            ->assertOk()->assertJsonPath('data.summary.0', 'Bob asked for the rent today.')->assertJsonPath('data.highlights.0.message_id', $ask);

        Http::assertNotSent(fn ($r) => str_contains(json_encode($r->data()), 'locked chat secret') || str_contains(json_encode($r->data()), 'sensitive diagnosis'));
    }

    public function test_related_files_send_names_never_the_files(): void
    {
        $chat = $this->direct($this->alice, $this->bob);
        $contract = $this->send($this->bob, $chat, ['type' => 'document', 'body' => 'Rental contract', 'files' => [UploadedFile::fake()->create('contract.pdf', 20, 'application/pdf')]])->json('data.id');
        $this->send($this->bob, $chat, ['type' => 'text', 'body' => 'What did the contract say about the deposit?'])->assertCreated();

        $this->reply = '[{"ref": 1, "why": "The rental contract"}]';
        $this->as($this->alice);
        $this->postJson("/api/mobile/v1/chat/conversations/{$chat}/related-files", [], $this->headers())
            ->assertOk()->assertJsonPath('data.files.0.message_id', $contract)->assertJsonPath('data.files.0.type', 'document');
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

    private function send(User $sender, string $conversation, array $data): \Illuminate\Testing\TestResponse
    {
        $this->as($sender);

        return $this->post("/api/mobile/v1/chat/conversations/{$conversation}/messages", $data, $this->headers() + ['Accept' => 'application/json']);
    }
}
