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
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\AI\Models\AiMediaGeneration;
use Modules\AI\Models\AiPlan;
use Modules\AI\Models\AiProvider;
use Modules\AI\Models\AiRequest;
use Modules\AI\Models\AiSubscription;
use Modules\AI\Models\AiTrialControl;
use Modules\AI\Models\AiUsageSession;
use Modules\AI\Repositories\AiProviderRepository;
use Modules\Chat\Models\ChatContact;
use Modules\Chat\Models\ChatMessage;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * Every AI tool in the chat needs a DORR AI plan (decision 2026-10-15) — the same check the DORR AI
 * chat makes, using up the same usage window — and images (spec 50, 51) also count against the
 * plan's daily images. An image is a draft only its maker sees until they send it, and then it is
 * an ordinary image message marked as made by AI.
 */
class ChatAiPlanAndImagesTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    /** A 1×1 PNG, as the fake image model's answer. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config(['ai.chat.verification.enabled' => false]);
        $flag = Flag::create(['code' => 'sa']);
        $saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR', 'decimal_places' => 2])->id, 'status' => true]);
        Language::create(['code' => 'en', 'direction' => 'ltr', 'is_default_website' => true, 'is_default_dashboard' => true, 'stores_translation' => true, 'status' => true, 'flag_id' => $flag->id]);
        $make = fn (string $name, string $phone) => User::create(['name' => $name, 'phone' => $phone, 'country_id' => $saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->alice = $make('Alice', '+966500000001');
        $this->bob = $make('Bob', '+966500000002');
        foreach ([[$this->alice, $this->bob], [$this->bob, $this->alice]] as [$owner, $contact]) {
            ChatContact::query()->create(['owner_type' => 'user', 'owner_id' => $owner->id, 'name' => $contact->name, 'phone' => $contact->phone, 'contact_type' => 'user', 'contact_id' => $contact->id, 'source' => 'manual']);
        }

        app(AiProviderRepository::class)->ensureDefaults();
        $openai = AiProvider::query()->where('key', 'openai')->firstOrFail();
        $openai->update(['is_enabled' => true, 'is_default' => true, 'api_key' => 'sk-test', 'model' => 'gpt-4o-mini']);
        $openai->models()->create(['model_key' => 'gpt-image-1', 'display_name' => 'Image', 'capabilities' => ['image_generation'], 'is_active' => true]);

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'Good morning']]]]),
            'api.openai.com/v1/images/generations' => Http::response(['data' => [['b64_json' => self::PNG]]]),
            'api.openai.com/v1/images/edits' => Http::response(['data' => [['b64_json' => self::PNG]]]),
        ]);
    }

    // ------------------------------------------------------------------ the plan

    public function test_without_a_plan_no_ai_tool_works_and_the_app_is_told_why(): void
    {
        $chat = $this->direct($this->bob, $this->alice);
        $message = $this->send($this->bob, $chat, ['type' => 'text', 'body' => 'صباح الخير'])->json('data.id');

        $this->as($this->alice);
        $this->getJson('/api/mobile/v1/chat/ai', $this->headers())->assertOk()
            ->assertJsonPath('data.translate', true)
            ->assertJsonPath('data.plan.allowed', false)->assertJsonPath('data.plan.reason', 'no_plan');
        $this->postJson("/api/mobile/v1/chat/messages/{$message}/translate", ['to' => 'en'], $this->headers())
            ->assertStatus(402)->assertJsonPath('error_code', 'chat_ai_plan_no_plan')->assertJsonPath('message', __('chat.errors.ai_plan_no_plan'));
        $this->postJson("/api/mobile/v1/chat/conversations/{$chat}/ai/image", ['prompt' => 'a cat'], $this->headers())
            ->assertStatus(402)->assertJsonPath('error_code', 'chat_ai_plan_no_plan');

        Http::assertNothingSent();
    }

    public function test_the_free_trial_allows_it_and_uses_up_the_same_window_as_the_dorr_ai_chat(): void
    {
        $this->trialPlan();
        $chat = $this->direct($this->bob, $this->alice);
        $message = $this->send($this->bob, $chat, ['type' => 'text', 'body' => 'صباح الخير'])->json('data.id');

        $this->as($this->alice);
        // Reading what the app can show starts nothing.
        $this->getJson('/api/mobile/v1/chat/ai', $this->headers())->assertOk()->assertJsonPath('data.plan.allowed', true);
        $this->assertSame(0, AiSubscription::query()->count());

        $this->postJson("/api/mobile/v1/chat/messages/{$message}/translate", ['to' => 'en'], $this->headers())->assertOk();
        // Using a tool started the trial and its usage window, exactly like a DORR AI chat message.
        $this->assertSame(1, AiSubscription::query()->where('owner_id', $this->alice->id)->count());
        $this->assertSame(1, AiUsageSession::query()->where('owner_id', $this->alice->id)->count());
    }

    public function test_an_ended_trial_or_a_suspended_subscription_locks_the_tools_and_a_paid_plan_opens_them(): void
    {
        $this->trialPlan();
        AiTrialControl::query()->create(['owner_type' => 'user', 'owner_id' => $this->alice->id, 'trial_status' => AiTrialControl::TRIAL_ENDED, 'abuse_status' => AiTrialControl::ABUSE_CLEAR]);
        $chat = $this->direct($this->bob, $this->alice);
        $message = $this->send($this->bob, $chat, ['type' => 'text', 'body' => 'hello'])->json('data.id');

        $this->as($this->alice);
        $this->getJson('/api/mobile/v1/chat/ai', $this->headers())->assertJsonPath('data.plan.allowed', false)->assertJsonPath('data.plan.reason', 'trial_ended');
        $this->postJson("/api/mobile/v1/chat/messages/{$message}/translate", ['to' => 'en'], $this->headers())
            ->assertStatus(402)->assertJsonPath('error_code', 'chat_ai_plan_trial_ended')->assertJsonPath('data.reason', 'trial_ended');

        $paid = AiPlan::query()->create(['name' => 'Plus', 'code' => 'plus', 'is_trial' => false, 'is_active' => true, 'sort_order' => 2, 'usage_minutes' => 600, 'cooldown_minutes' => 5]);
        AiSubscription::query()->create(['owner_type' => 'user', 'owner_id' => $this->alice->id, 'plan_id' => $paid->id, 'starts_at' => now(), 'status' => AiSubscription::STATUS_ACTIVE]);
        $this->getJson('/api/mobile/v1/chat/ai', $this->headers())->assertJsonPath('data.plan.allowed', true);
        $this->postJson("/api/mobile/v1/chat/messages/{$message}/translate", ['to' => 'en'], $this->headers())->assertOk();
    }

    // ------------------------------------------------------------------ images (spec 50, 51)

    public function test_an_image_from_a_description_is_a_draft_until_sent_then_an_image_message_marked_as_ai(): void
    {
        $this->trialPlan(imagesPerDay: 2);
        $chat = $this->direct($this->alice, $this->bob);

        $draft = $this->postJson("/api/mobile/v1/chat/conversations/{$chat}/ai/image", ['prompt' => 'a cat in a spacesuit'], $this->headers())
            ->assertOk()->assertJsonPath('data.kind', 'generate')->json('data');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'images/generations') && $r['model'] === 'gpt-image-1' && $r['prompt'] === 'a cat in a spacesuit');
        $this->assertSame(0, ChatMessage::query()->where('type', 'image')->count(), 'nothing is sent by itself');
        $this->assertSame([AiMediaGeneration::STATUS_COMPLETED, 'chat.image_generate'], [AiMediaGeneration::query()->sole()->status, AiRequest::query()->sole()->source]);

        // Only its maker can send it, and only to that chat.
        $this->as($this->bob);
        $this->postJson("/api/mobile/v1/chat/conversations/{$chat}/ai/image/send", ['draft' => $draft['draft']], $this->headers())->assertNotFound();

        $this->as($this->alice);
        $this->postJson("/api/mobile/v1/chat/conversations/{$chat}/ai/image/send", ['draft' => $draft['draft'], 'body' => 'look!'], $this->headers())
            ->assertCreated()->assertJsonPath('data.type', 'image')->assertJsonPath('data.body', 'look!')->assertJsonPath('data.meta.ai_generated', true);
        // A draft is sent once.
        $this->postJson("/api/mobile/v1/chat/conversations/{$chat}/ai/image/send", ['draft' => $draft['draft']], $this->headers())->assertNotFound();
    }

    public function test_images_count_against_the_plans_daily_images(): void
    {
        $plan = $this->trialPlan(imagesPerDay: 1);
        $chat = $this->direct($this->alice, $this->bob);

        $this->postJson("/api/mobile/v1/chat/conversations/{$chat}/ai/image", ['prompt' => 'a cat'], $this->headers())->assertOk();
        $this->postJson("/api/mobile/v1/chat/conversations/{$chat}/ai/image", ['prompt' => 'a dog'], $this->headers())
            ->assertStatus(429)->assertJsonPath('error_code', 'chat_ai_image_daily_limit')->assertJsonPath('data.limit', 1);

        // A plan without images: none at all.
        $plan->update(['image_daily_limit' => 0]);
        $this->postJson("/api/mobile/v1/chat/conversations/{$chat}/ai/image", ['prompt' => 'a bird'], $this->headers())
            ->assertStatus(402)->assertJsonPath('error_code', 'chat_ai_image_not_in_plan');
    }

    public function test_an_image_in_the_chat_is_edited_as_described_into_a_draft(): void
    {
        $this->trialPlan(imagesPerDay: 5);
        $chat = $this->direct($this->bob, $this->alice);
        $photo = $this->send($this->bob, $chat, ['type' => 'image', 'files' => [UploadedFile::fake()->image('p.jpg', 40, 40)]])->assertCreated()->json('data.id');
        $text = $this->send($this->bob, $chat, ['type' => 'text', 'body' => 'hi'])->json('data.id');

        $this->as($this->alice);
        $this->postJson("/api/mobile/v1/chat/messages/{$photo}/ai/edit-image", ['prompt' => 'make it black and white'], $this->headers())
            ->assertOk()->assertJsonPath('data.kind', 'edit');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'images/edits'));
        $this->assertSame('chat.image_edit', AiRequest::query()->latest('id')->first()->source);

        $this->postJson("/api/mobile/v1/chat/messages/{$text}/ai/edit-image", ['prompt' => 'x'], $this->headers())
            ->assertUnprocessable()->assertJsonPath('error_code', 'chat_ai_not_image');
    }

    private function trialPlan(?int $imagesPerDay = null): AiPlan
    {
        return AiPlan::query()->create(['name' => 'Trial', 'code' => 'trial', 'is_trial' => true, 'is_active' => true, 'sort_order' => 1, 'usage_minutes' => 600, 'cooldown_minutes' => 5, 'image_daily_limit' => $imagesPerDay]);
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
