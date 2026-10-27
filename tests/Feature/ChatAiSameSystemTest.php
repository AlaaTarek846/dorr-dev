<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\AI\Models\AiFailover;
use Modules\AI\Models\AiProvider;
use Modules\AI\Models\AiRequest;
use Modules\AI\Models\AiRoutingPolicy;
use Modules\AI\Models\AiRoutingRule;
use Modules\AI\Models\AiSafetyEvent;
use Modules\AI\Models\AiSafetyPolicy;
use Modules\AI\Models\AiSafetyRule;
use Modules\AI\Models\AiTrialControl;
use Modules\AI\Models\AiUsage;
use Modules\AI\Repositories\AiProviderRepository;
use Modules\AI\Repositories\AiRequestRepository;
use Modules\Chat\Models\ChatContact;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * The AI tools inside the chat run on the DORR AI system itself (AiTaskRunner), not on a side
 * path: the admin's AI block, safety rules, routing policy and fallback, the registered
 * speech-to-text model, and the usage log all apply to them exactly as to the DORR AI chat.
 */
class ChatAiSameSystemTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    private int $openAiStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Config::set('ai.chat.verification.enabled', false);
        $sar = Currency::create(['code' => 'SAR', 'symbol' => 'SAR', 'decimal_places' => 2]);
        $flag = Flag::create(['code' => 'sa']);
        $saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => $sar->id, 'status' => true]);
        Language::create(['code' => 'en', 'direction' => 'ltr', 'is_default_website' => true, 'is_default_dashboard' => true, 'stores_translation' => true, 'status' => true, 'flag_id' => $flag->id]);

        $make = fn (string $name, string $phone) => User::create(['name' => $name, 'phone' => $phone, 'country_id' => $saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->alice = $make('Alice', '+966500000001');
        $this->bob = $make('Bob', '+966500000002');
        foreach ([[$this->alice, $this->bob], [$this->bob, $this->alice]] as [$owner, $contact]) {
            ChatContact::query()->create(['owner_type' => 'user', 'owner_id' => $owner->id, 'name' => $contact->name, 'phone' => $contact->phone, 'contact_type' => 'user', 'contact_id' => $contact->id, 'source' => 'manual']);
        }

        app(AiProviderRepository::class)->ensureDefaults();
        // The chat's AI tools need a DORR AI plan (2026-10-15): the admin's free trial.
        \Modules\AI\Models\AiPlan::query()->create(['name' => 'Trial', 'code' => 'trial-'.class_basename(static::class), 'is_trial' => true, 'is_active' => true, 'sort_order' => 1, 'usage_minutes' => 600, 'cooldown_minutes' => 5]);
        $this->provider('openai')->update(['is_enabled' => true, 'is_default' => true, 'api_key' => 'sk-test', 'model' => 'gpt-4o-mini']);

        Http::fake([
            'api.openai.com/v1/chat/completions' => fn () => $this->openAiStatus === 200
                ? Http::response(['choices' => [['message' => ['content' => 'Good morning']]]])
                : Http::response(['error' => ['message' => 'The server had an error']], $this->openAiStatus),
            'api.anthropic.com/v1/messages' => Http::response(['content' => [['type' => 'text', 'text' => 'Good morning from Claude']]]),
            'api.openai.com/v1/audio/transcriptions' => Http::response(['text' => 'See you at five']),
        ]);
    }

    public function test_each_chat_ai_call_is_logged_like_the_ai_chat_with_its_tool(): void
    {
        $message = $this->textFromBob('صباح الخير');

        $this->as($this->alice);
        $this->postJson("/api/mobile/v1/chat/messages/{$message}/translate", ['to' => 'en'], $this->headers())
            ->assertOk()->assertJsonPath('data.text', 'Good morning');

        $request = AiRequest::query()->sole();
        $this->assertSame('chat.translate', $request->source);
        $this->assertSame([$this->alice->getMorphClass(), $this->alice->id], [$request->owner_type, (int) $request->owner_id]);
        $this->assertSame([AiRequest::STATUS_COMPLETED, 'gpt-4o-mini'], [$request->status, $request->model_key]);
        $this->assertSame($this->provider('openai')->id, $request->provider_id);
        $this->assertNotNull($request->response);
        $this->assertSame(1, AiUsage::query()->where('request_id', $request->id)->count());
    }

    public function test_someone_the_admin_blocked_from_ai_gets_no_ai_in_the_chat_either(): void
    {
        $message = $this->textFromBob('hello');
        AiTrialControl::query()->create(['owner_type' => 'user', 'owner_id' => $this->alice->id, 'trial_status' => AiTrialControl::TRIAL_ELIGIBLE, 'abuse_status' => AiTrialControl::ABUSE_BLOCKED]);

        $this->as($this->alice);
        $this->postJson("/api/mobile/v1/chat/messages/{$message}/translate", ['to' => 'en'], $this->headers())
            ->assertForbidden()->assertJsonPath('error_code', 'chat_ai_blocked');

        Http::assertNothingSent();
    }

    public function test_the_admins_safety_rules_apply_to_the_chat_tools(): void
    {
        $policy = AiSafetyPolicy::query()->create(['name' => 'Weapons', 'risk_level' => 'high', 'is_active' => true]);
        AiSafetyRule::query()->create(['safety_policy_id' => $policy->id, 'name' => 'No weapons', 'condition' => ['type' => 'keyword_match', 'keywords' => ['explosive']], 'action' => AiSafetyRule::ACTION_BLOCK, 'priority' => 10, 'is_active' => true]);
        $message = $this->textFromBob('how to make an explosive');

        $this->as($this->alice);
        $this->postJson("/api/mobile/v1/chat/messages/{$message}/translate", ['to' => 'en'], $this->headers())
            ->assertUnprocessable()->assertJsonPath('error_code', 'chat_ai_message_blocked');

        Http::assertNothingSent();
        $this->assertSame(1, AiSafetyEvent::query()->where('owner_id', $this->alice->id)->where('action_taken', AiSafetyRule::ACTION_BLOCK)->count());
    }

    public function test_the_admins_routing_policy_picks_the_provider_and_falls_back_when_it_fails(): void
    {
        $this->provider('anthropic')->update(['is_enabled' => true, 'api_key' => 'sk-ant', 'model' => 'claude-3-5-haiku-latest']);
        $policy = AiRoutingPolicy::query()->create(['name' => 'Global', 'scope_type' => 'global', 'fallback_enabled' => true, 'priority' => 1, 'is_active' => true]);
        AiRoutingRule::query()->create(['routing_policy_id' => $policy->id, 'provider_id' => $this->provider('openai')->id, 'model_key' => 'gpt-4.1-mini', 'priority' => 10, 'is_active' => true]);
        AiRoutingRule::query()->create(['routing_policy_id' => $policy->id, 'provider_id' => $this->provider('anthropic')->id, 'model_key' => 'claude-3-5-haiku-latest', 'priority' => 5, 'is_active' => true]);
        $message = $this->textFromBob('مرحبا');

        // The policy's first choice, with the model it names (not the provider's default).
        $this->as($this->alice);
        $this->postJson("/api/mobile/v1/chat/messages/{$message}/translate", ['to' => 'en'], $this->headers())
            ->assertOk()->assertJsonPath('data.text', 'Good morning');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'openai.com/v1/chat/completions') && $r['model'] === 'gpt-4.1-mini');

        // OpenAI down: the next provider answers, and the hand-over is recorded.
        $this->openAiStatus = 500;
        $this->postJson("/api/mobile/v1/chat/messages/{$message}/translate", ['to' => 'fr'], $this->headers())
            ->assertOk()->assertJsonPath('data.text', 'Good morning from Claude');

        $failed = AiRequest::query()->latest('id')->first();
        $this->assertSame([$this->provider('anthropic')->id, 'claude-3-5-haiku-latest'], [$failed->provider_id, $failed->model_key]);
        $this->assertSame(1, AiFailover::query()->where('request_id', $failed->id)->count());
    }

    public function test_voice_to_text_uses_the_speech_to_text_model_registered_by_the_admin(): void
    {
        $this->provider('openai')->models()->create(['model_key' => 'gpt-4o-transcribe', 'display_name' => 'Transcribe', 'capabilities' => ['speech_to_text'], 'is_active' => true]);
        $chat = $this->direct($this->bob, $this->alice);
        $voice = $this->send($this->bob, $chat, [
            'type' => 'voice', 'duration_ms' => 3000, 'files' => [UploadedFile::fake()->create('note.m4a', 40, 'audio/mp4')],
        ])->assertCreated()->json('data.id');

        $this->as($this->alice);
        $this->postJson("/api/mobile/v1/chat/messages/{$voice}/transcribe", [], $this->headers())
            ->assertOk()->assertJsonPath('data.text', 'See you at five');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'audio/transcriptions') && str_contains($r->body(), 'gpt-4o-transcribe'));
        $this->assertSame(['chat.transcribe', 'gpt-4o-transcribe'], [AiRequest::query()->sole()->source, AiRequest::query()->sole()->model_key]);
    }

    public function test_the_other_chat_tools_are_logged_under_their_own_names(): void
    {
        $chat = $this->direct($this->bob, $this->alice);
        $this->textFromBob('Shall we meet tomorrow at 5?', $chat);

        $this->as($this->alice);
        $this->postJson("/api/mobile/v1/chat/conversations/{$chat}/summarize", [], $this->headers())->assertOk();
        $this->postJson("/api/mobile/v1/chat/conversations/{$chat}/smart-replies", [], $this->headers())->assertOk();

        $this->assertEqualsCanonicalizing(['chat.summary', 'chat.smart_replies'], AiRequest::query()->pluck('source')->all());
    }

    public function test_suggested_replies_to_a_medical_question_are_written_under_the_high_stakes_rules(): void
    {
        $chat = $this->direct($this->bob, $this->alice);
        $this->textFromBob('عندي صداع من يومين وباخد بنادول، أزود الجرعة؟', $chat);

        $this->as($this->alice);
        $this->postJson("/api/mobile/v1/chat/conversations/{$chat}/smart-replies", [], $this->headers())->assertOk();

        // The reply request carries the medicine rules as a system message; nothing is appended
        // to the suggestions themselves (the person sends them as their own words).
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'chat/completions')
            && str_contains((string) json_encode($r['messages']), 'You suggest replies')
            && collect($r['messages'])->contains(fn ($m) => $m['role'] === 'system' && str_contains($m['content'], 'medicine (high stakes)')));
    }

    public function test_the_admin_requests_list_tells_the_ai_chat_and_the_tools_apart(): void
    {
        $base = ['owner_type' => 'user', 'owner_id' => $this->alice->id, 'provider_id' => $this->provider('openai')->id, 'status' => AiRequest::STATUS_COMPLETED];
        AiRequest::query()->create($base + ['source' => null]);
        AiRequest::query()->create($base + ['source' => 'chat.translate']);
        AiRequest::query()->create($base + ['source' => 'discover.ask']);

        $listed = function (string $source) {
            request()->merge(['source' => $source]);

            return app(AiRequestRepository::class)->index()->pluck('source')->all();
        };

        $this->assertSame([null], $listed('ai_chat'));
        $this->assertEqualsCanonicalizing(['chat.translate', 'discover.ask'], $listed('tools'));
        $this->assertSame(['chat.translate'], $listed('chat.translate'));
        $this->assertCount(3, $listed(''));
    }

    private function provider(string $key): AiProvider
    {
        return AiProvider::query()->where('key', $key)->firstOrFail();
    }

    private function textFromBob(string $body, ?string $chat = null): string
    {
        $chat ??= $this->direct($this->bob, $this->alice);

        return $this->send($this->bob, $chat, ['type' => 'text', 'body' => $body])->assertCreated()->json('data.id');
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
