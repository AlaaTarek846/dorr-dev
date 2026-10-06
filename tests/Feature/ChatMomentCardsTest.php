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
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Models\ChatMoment;
use Modules\Chat\Models\ChatScheduledMessage;
use Modules\User\Models\User;
use Modules\Wallet\Database\Seeders\FinancialCategorySeeder;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Models\WalletSetting;
use Modules\Wallet\Services\PinService;
use Modules\Wallet\Services\WalletService;
use Tests\TestCase;

/**
 * Greeting cards (DORR Moments, spec 161–167; AT-MOM-03): the occasion's look, my voice and
 * photos, a wallet gift, a sealed surprise, a card scheduled in the recipient's time, AI greetings.
 */
class ChatMomentCardsTest extends TestCase
{
    use RefreshDatabase;

    private Country $saudi;

    private User $alice;

    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $flag = Flag::create(['code' => 'sa']);
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR', 'decimal_places' => 2])->id, 'status' => true]);
        Language::create(['code' => 'en', 'direction' => 'ltr', 'is_default_website' => true, 'is_default_dashboard' => true, 'stores_translation' => true, 'status' => true, 'flag_id' => $flag->id]);
        $this->seed(FinancialCategorySeeder::class);
        WalletSetting::query()->where('country_id', $this->saudi->id)->update(['transfers_enabled' => true]);

        $make = fn (string $name, string $phone) => User::create(['name' => $name, 'phone' => $phone, 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->alice = $make('Alice', '+966500000001');
        $this->bob = $make('Bob', '+966500000002');
        app(PinService::class)->set($this->alice, '1234');
        foreach ([[$this->alice, $this->bob], [$this->bob, $this->alice]] as [$owner, $contact]) {
            ChatContact::query()->create(['owner_type' => 'user', 'owner_id' => $owner->id, 'name' => $contact->name, 'phone' => $contact->phone, 'contact_type' => 'user', 'contact_id' => $contact->id, 'source' => 'manual']);
        }

        config(['services.onesignal.app_id' => 'app', 'services.onesignal.rest_api_key' => 'rest']);
        Http::fake(['api.onesignal.com/*' => Http::response(['id' => 'n1'])]);
        NotificationDevice::query()->create(['owner_type' => User::class, 'owner_id' => $this->bob->id, 'player_id' => 'bob-phone', 'platform' => 'android']);
    }

    public function test_a_card_in_the_occasions_look_with_my_voice_and_a_photo(): void
    {
        $chat = $this->direct();
        $eid = ChatMoment::query()->where('key', 'eid_al_fitr')->firstOrFail();

        $this->card($chat, ['moment_id' => $eid->id, 'text' => 'Eid Mubarak to you and the family!',
            'voice' => UploadedFile::fake()->create('me.m4a', 120, 'audio/mp4'), 'photos' => [UploadedFile::fake()->image('us.jpg', 400, 400)]])
            ->assertCreated()
            ->assertJsonPath('data.type', 'moment_card')
            ->assertJsonPath('data.meta.card.title', 'Eid al-Fitr')
            ->assertJsonPath('data.meta.card.animation', 'fireworks')
            ->assertJsonCount(2, 'data.attachments');

        Http::assertSent(fn ($r) => str_contains(json_encode($r['contents'] ?? []), 'Greeting card'));
    }

    public function test_a_surprise_stays_sealed_everywhere_until_its_time(): void
    {
        $this->travelTo(Carbon::parse('2026-10-11 20:00', 'UTC'));
        $chat = $this->direct();
        $this->card($chat, ['personal_kind' => 'birthday', 'text' => 'The party is at 9 🎂', 'reveal_at' => '2026-10-12T00:00:00+03:00',
            'photos' => [UploadedFile::fake()->image('cake.jpg', 300, 300)]])->assertCreated()->assertJsonPath('data.sealed', false); // mine: I see it

        $this->as($this->bob);
        $msg = collect($this->getJson("/api/mobile/v1/chat/conversations/{$chat}/messages", $this->headers())->json('data.messages'))->firstWhere('type', 'moment_card');
        $this->assertTrue($msg['sealed']);
        $this->assertNull($msg['body']);
        $this->assertSame([], $msg['attachments']);
        $this->assertSame('Happy birthday!', $msg['meta']['card']['title']);
        $row = collect($this->getJson('/api/mobile/v1/chat/conversations', $this->headers())->json('data'))->firstWhere('id', $chat);
        $this->assertNull($row['last_message']['body']);
        Http::assertNotSent(fn ($r) => str_contains(json_encode($r->data()), 'party'));

        $this->travelTo(Carbon::parse('2026-10-11 21:01', 'UTC')); // midnight in Riyadh
        $msg = collect($this->getJson("/api/mobile/v1/chat/conversations/{$chat}/messages", $this->headers())->json('data.messages'))->firstWhere('type', 'moment_card');
        $this->assertFalse($msg['sealed']);
        $this->assertSame('The party is at 9 🎂', $msg['body']);
        $this->assertCount(1, $msg['attachments']);
    }

    public function test_a_gift_goes_through_the_wallet_with_the_pin(): void
    {
        $wallets = app(WalletService::class);
        $wallets->credit($wallets->firstOrCreateWallet($this->alice, $this->saudi), 50000, WalletBucket::Withdrawable, WalletTransactionType::Topup);
        $chat = $this->direct();

        $this->card($chat, ['personal_kind' => 'graduation', 'text' => 'Proud of you', 'gift_amount_minor' => 10000], '0000')->assertStatus(422);
        $this->assertSame(0, ChatMessage::query()->where('type', 'moment_card')->count());

        $this->card($chat, ['personal_kind' => 'graduation', 'text' => 'Proud of you', 'gift_amount_minor' => 10000], '1234')
            ->assertCreated()->assertJsonPath('data.meta.card.gift.amount_minor', 10000);
        $this->assertSame(1, ChatMessage::query()->where('type', 'wallet_transfer')->count());
    }

    public function test_a_card_scheduled_for_midnight_where_they_are_goes_out_once(): void
    {
        $this->travelTo(Carbon::parse('2026-10-10 12:00', 'UTC'));
        $chat = $this->direct();

        // Bob's phone is in Riyadh (the app says so on every chat request).
        $this->as($this->bob);
        $this->getJson('/api/mobile/v1/chat/conversations', $this->headers() + ['X-Timezone' => 'Asia/Riyadh'])->assertOk();
        $this->assertSame('Asia/Riyadh', $this->bob->fresh()->timezone);

        $this->as($this->alice);
        $this->card($chat, ['personal_kind' => 'birthday', 'text' => 'Happy birthday Bob!', 'send_at' => '2026-10-12 00:00', 'schedule_zone' => 'recipient',
            'photos' => [UploadedFile::fake()->image('cake.jpg', 300, 300)]])
            ->assertCreated()->assertJsonPath('data.scheduled.type', 'moment_card')->assertJsonPath('data.scheduled.timezone', 'Asia/Riyadh');
        $this->assertSame('2026-10-11 21:00:00', ChatScheduledMessage::query()->first()->send_at->utc()->toDateTimeString());

        // A gift can't wait for later.
        $this->card($chat, ['personal_kind' => 'birthday', 'send_at' => '2026-10-12 00:00', 'gift_amount_minor' => 100], '1234')
            ->assertUnprocessable()->assertJsonPath('error_code', 'chat_card_gift_now_only');

        $this->travelTo(Carbon::parse('2026-10-11 21:01', 'UTC'));
        $this->artisan('chat:send-scheduled')->assertSuccessful();
        $this->artisan('chat:send-scheduled')->assertSuccessful(); // a retry sends nothing twice
        $cards = ChatMessage::query()->where('type', 'moment_card')->get();
        $this->assertCount(1, $cards);
        $this->assertCount(1, $cards->first()->getMedia(ChatMessage::ATTACHMENTS));
        $this->assertSame('Happy birthday!', $cards->first()->meta['card']['title']);
    }

    public function test_the_ai_offers_three_greetings_to_edit(): void
    {
        app(AiProviderRepository::class)->ensureDefaults();
        AiProvider::query()->where('key', 'openai')->firstOrFail()->update(['is_enabled' => true, 'is_default' => true, 'api_key' => 'sk-test', 'model' => 'gpt-4o-mini']);
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => '["Eid Mubarak, Mom!", "Wishing you joy this Eid", "Happy Eid to the best mom"]']]]])]);

        $this->as($this->alice);
        $eid = ChatMoment::query()->where('key', 'eid_al_fitr')->value('id');
        $this->postJson('/api/mobile/v1/chat/moments/greetings', ['moment_id' => $eid, 'relation' => 'family', 'tone' => 'warm', 'name' => 'Mom'], $this->headers())
            ->assertOk()->assertJsonCount(3, 'data.greetings')->assertJsonPath('data.greetings.0', 'Eid Mubarak, Mom!');
    }

    // ------------------------------------------------------------------ helpers

    private function direct(): string
    {
        $this->as($this->alice);

        return $this->postJson('/api/mobile/v1/chat/conversations/direct', ['participant_id' => $this->bob->id], $this->headers())->assertOk()->json('data.id');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function card(string $chat, array $data, ?string $pin = null): \Illuminate\Testing\TestResponse
    {
        $this->as($this->alice);

        return $this->post("/api/mobile/v1/chat/conversations/{$chat}/moment-card", $data, $this->headers() + ['Accept' => 'application/json'] + ($pin ? ['X-Wallet-Pin' => $pin] : []));
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
}
