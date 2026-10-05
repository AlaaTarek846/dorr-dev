<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use App\Models\NotificationDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Modules\Chat\Models\ChatContact;
use Modules\Chat\Models\ChatPrivacySetting;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * Privacy without circles: sensitive messages (107), quick privacy mode (111), its schedule (112),
 * the summary afterwards (113) and private notifications folded into one (104).
 */
class ChatPrivacyModeTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();

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

        config(['services.onesignal.app_id' => 'app', 'services.onesignal.rest_api_key' => 'rest']);
        Http::fake(['api.onesignal.com/*' => Http::response(['id' => 'n1'])]);
        NotificationDevice::query()->create(['owner_type' => User::class, 'owner_id' => $this->bob->id, 'player_id' => 'bob-phone', 'platform' => 'android']);
    }

    public function test_a_sensitive_message_never_shows_its_content(): void
    {
        $chat = $this->direct($this->alice, $this->bob);
        $this->send($this->alice, $chat, ['type' => 'text', 'body' => 'my bank PIN is 4321', 'sensitive' => true])
            ->assertCreated()->assertJsonPath('data.is_sensitive', true);

        // Bob shows names and text normally — but not this one.
        Http::assertSent(fn ($r) => $r['headings']['en'] === 'Alice' && $r['contents']['en'] === 'New message');
        Http::assertNotSent(fn ($r) => str_contains(json_encode($r->data()), '4321'));

        $this->as($this->bob);
        $row = collect($this->getJson('/api/mobile/v1/chat/conversations', $this->headers())->json('data'))->firstWhere('id', $chat);
        $this->assertNull($row['last_message']['body']);
        $this->assertTrue($row['last_message']['is_sensitive']);
    }

    public function test_quick_privacy_mode_hides_everything_then_sums_up(): void
    {
        $chat = $this->direct($this->alice, $this->bob);

        $this->as($this->bob);
        $this->putJson('/api/mobile/v1/chat/privacy-mode', ['minutes' => 60], $this->headers())->assertOk()->assertJsonPath('data.privacy_mode.on', true);

        $this->send($this->alice, $chat, ['type' => 'text', 'body' => 'are you free tonight?'])->assertCreated();
        $this->send($this->alice, $chat, ['type' => 'text', 'body' => 'call me'])->assertCreated();
        Http::assertSent(fn ($r) => $r['headings']['en'] === 'Dorr' && $r['contents']['en'] === 'New message' && $r['data']['private'] === '1');
        Http::assertNotSent(fn ($r) => ($r['headings']['en'] ?? null) === 'Alice');

        // Off: what came in meanwhile.
        $this->as($this->bob);
        $this->deleteJson('/api/mobile/v1/chat/privacy-mode', [], $this->headers())->assertOk()
            ->assertJsonPath('data.summary.messages', 2)->assertJsonPath('data.summary.conversations', 1)
            ->assertJsonPath('data.settings.privacy_mode.on', false);
    }

    public function test_the_privacy_schedule_runs_past_midnight_in_my_time_zone(): void
    {
        $this->as($this->bob);
        $this->patchJson('/api/mobile/v1/chat/privacy', ['privacy_schedule' => ['from' => '22:00', 'to' => '07:00', 'timezone' => 'Asia/Riyadh']], $this->headers())
            ->assertOk()->assertJsonPath('data.privacy_mode.schedule.from', '22:00');

        $settings = ChatPrivacySetting::query()->where('owner_id', $this->bob->id)->firstOrFail();
        // Riyadh is UTC+3: 20:00 UTC = 23:00 there (on), 03:00 UTC = 06:00 (on), 09:00 UTC = 12:00 (off).
        $this->assertTrue($settings->privacyModeOn(Carbon::parse('2026-10-05 20:00', 'UTC')));
        $this->assertTrue($settings->privacyModeOn(Carbon::parse('2026-10-06 03:00', 'UTC')));
        $this->assertFalse($settings->privacyModeOn(Carbon::parse('2026-10-06 09:00', 'UTC')));

        $chat = $this->direct($this->alice, $this->bob);
        $this->travelTo(Carbon::parse('2026-10-05 20:00', 'UTC'));
        $this->send($this->alice, $chat, ['type' => 'text', 'body' => 'late message'])->assertCreated();
        Http::assertSent(fn ($r) => $r['headings']['en'] === 'Dorr');
        Http::assertNotSent(fn ($r) => ($r['contents']['en'] ?? null) === 'late message');
    }

    public function test_the_summary_after_a_timed_mode(): void
    {
        $chat = $this->direct($this->alice, $this->bob);
        $from = now()->subMinute();
        $this->send($this->alice, $chat, ['type' => 'text', 'body' => 'one'])->assertCreated();
        $this->send($this->bob, $chat, ['type' => 'text', 'body' => 'mine — not counted'])->assertCreated();

        $this->as($this->bob);
        $this->getJson('/api/mobile/v1/chat/privacy-mode/summary?from='.urlencode($from->toIso8601String()), $this->headers())
            ->assertOk()->assertJsonPath('data.messages', 1);
    }

    // ================================================================ helpers

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

        return $this->postJson("/api/mobile/v1/chat/conversations/{$conversation}/messages", $data, $this->headers());
    }
}
