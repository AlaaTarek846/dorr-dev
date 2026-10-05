<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use App\Models\NotificationDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Modules\Chat\Models\ChatSetting;
use Modules\Chat\Support\BannedWords;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * Group moderation (slow mode, banned words, invite links that expire), calls switched off per
 * country, and the three notification privacy levels.
 */
class ChatModerationTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    private User $carol;

    /** In Egypt — where the tests switch calls off. */
    private User $omar;

    private Country $egypt;

    protected function setUp(): void
    {
        parent::setUp();

        $sar = Currency::create(['code' => 'SAR', 'symbol' => 'SAR', 'decimal_places' => 2]);
        $flag = Flag::create(['code' => 'sa']);
        $saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => $sar->id, 'status' => true]);
        $this->egypt = Country::create(['code' => 'EG', 'dial_code' => '+20', 'phone_length' => 10, 'is_default' => false, 'flag_id' => Flag::create(['code' => 'eg'])->id, 'currency_id' => $sar->id, 'status' => true]);
        Language::create(['code' => 'en', 'direction' => 'ltr', 'is_default_website' => true, 'is_default_dashboard' => true, 'stores_translation' => true, 'status' => true, 'flag_id' => $flag->id]);

        $make = fn (string $name, string $phone, Country $country) => User::create(['name' => $name, 'phone' => $phone, 'country_id' => $country->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->alice = $make('Alice', '+966500000001', $saudi);
        $this->bob = $make('Bob', '+966500000002', $saudi);
        $this->carol = $make('Carol', '+966500000003', $saudi);
        $this->omar = $make('Omar', '+201000000004', $this->egypt);

        config(['services.onesignal.app_id' => 'app', 'services.onesignal.rest_api_key' => 'rest']);
        Http::fake(['api.onesignal.com/*' => Http::response(['id' => 'n1'])]);
        NotificationDevice::query()->create(['owner_type' => User::class, 'owner_id' => $this->bob->id, 'player_id' => 'bob-phone', 'platform' => 'android']);
    }

    // ================================================================ slow mode

    public function test_slow_mode_makes_members_wait_but_not_admins(): void
    {
        $group = $this->group($this->alice, [$this->bob, $this->carol]);

        $this->as($this->alice);
        $this->patchJson("/api/mobile/v1/chat/groups/{$group}/settings", ['slow_mode_seconds' => 30], $this->headers())
            ->assertOk()->assertJsonPath('data.group.slow_mode_seconds', 30);

        // Everyone is told, in their language.
        $texts = collect($this->getJson("/api/mobile/v1/chat/conversations/{$group}/messages", $this->headers())->json('data.messages'))->pluck('system.text');
        $this->assertContains('Alice turned on slow mode: one message every 30 seconds', $texts);

        $this->send($this->bob, $group, ['type' => 'text', 'body' => 'first'])->assertCreated();
        $wait = $this->send($this->bob, $group, ['type' => 'text', 'body' => 'second'])
            ->assertStatus(429)->assertJsonPath('error_code', 'chat_slow_mode');
        $this->assertGreaterThan(0, $wait->json('data.retry_after'));
        $this->assertLessThanOrEqual(30, $wait->json('data.retry_after'));

        // Admins never wait.
        $this->send($this->alice, $group, ['type' => 'text', 'body' => 'one'])->assertCreated();
        $this->send($this->alice, $group, ['type' => 'text', 'body' => 'two'])->assertCreated();

        $this->travel(31)->seconds();
        $this->send($this->bob, $group, ['type' => 'text', 'body' => 'second, later'])->assertCreated();
    }

    public function test_slow_mode_only_takes_the_offered_steps_and_only_from_admins(): void
    {
        $group = $this->group($this->alice, [$this->bob]);

        $this->as($this->alice);
        $this->patchJson("/api/mobile/v1/chat/groups/{$group}/settings", ['slow_mode_seconds' => 45], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('slow_mode_seconds');

        $this->as($this->bob);
        $this->patchJson("/api/mobile/v1/chat/groups/{$group}/settings", ['slow_mode_seconds' => 60], $this->headers())
            ->assertForbidden()->assertJsonPath('error_code', 'chat_admins_only');
    }

    // ================================================================ banned words

    public function test_banned_words_stop_a_members_message_however_it_is_spelled(): void
    {
        $group = $this->group($this->alice, [$this->bob]);

        $this->as($this->alice);
        $this->patchJson("/api/mobile/v1/chat/groups/{$group}/settings", ['banned_words' => ['spam', 'كلمة وحشة']], $this->headers())->assertOk();

        $this->send($this->bob, $group, ['type' => 'text', 'body' => 'this is SPAM!'])
            ->assertUnprocessable()->assertJsonPath('error_code', 'chat_banned_word');
        // Stretched letters, other spacing, ة written as ه — still the same words.
        $this->send($this->bob, $group, ['type' => 'text', 'body' => 'دي كـلـمة   وحشه'])
            ->assertUnprocessable()->assertJsonPath('error_code', 'chat_banned_word');
        // A poll's options are checked too.
        $this->send($this->bob, $group, ['type' => 'poll', 'body' => 'Lunch?', 'poll_options' => ['pizza', 'spam']])
            ->assertUnprocessable()->assertJsonPath('error_code', 'chat_banned_word');

        // Never inside another word.
        $ok = $this->send($this->bob, $group, ['type' => 'text', 'body' => 'the spammer left'])->assertCreated()->json('data.id');

        // Editing can't sneak one in.
        $this->as($this->bob);
        $this->patchJson("/api/mobile/v1/chat/messages/{$ok}", ['body' => 'pure spam'], $this->headers())
            ->assertUnprocessable()->assertJsonPath('error_code', 'chat_banned_word');

        // Admins moderate — they may need to quote it.
        $this->send($this->alice, $group, ['type' => 'text', 'body' => 'no spam here please'])->assertCreated();
    }

    public function test_only_admins_see_the_banned_words_and_the_list_is_cleaned(): void
    {
        $group = $this->group($this->alice, [$this->bob]);

        $this->as($this->alice);
        $this->patchJson("/api/mobile/v1/chat/groups/{$group}/settings", ['banned_words' => [' spam ', 'SPAM', '', 'scam']], $this->headers())
            ->assertOk()->assertJsonPath('data.group.banned_words', ['spam', 'scam']);

        $this->as($this->bob);
        $this->getJson("/api/mobile/v1/chat/conversations/{$group}", $this->headers())->assertOk()->assertJsonPath('data.group.banned_words', null);
    }

    public function test_banned_word_matching(): void
    {
        $this->assertSame('أحمق', BannedWords::firstIn('يا احمق!', ['أحمق']));
        $this->assertSame('bad word', BannedWords::firstIn("a BAD\nword", ['bad word']));
        $this->assertNull(BannedWords::firstIn('classic', ['ass']));
        $this->assertNull(BannedWords::firstIn('anything', null));
    }

    // ================================================================ invite links that expire

    public function test_an_invite_link_can_expire(): void
    {
        $group = $this->group($this->alice, [$this->bob]);

        $this->as($this->alice);
        $link = $this->postJson("/api/mobile/v1/chat/groups/{$group}/invite/reset", ['expires_in_hours' => 1], $this->headers())->assertOk();
        $this->assertNotNull($link->json('data.expires_at'));
        $token = $link->json('data.token');

        $this->as($this->carol);
        $this->getJson("/api/mobile/v1/chat/invites/{$token}", $this->headers())->assertOk();

        $this->travel(2)->hours();
        $this->getJson("/api/mobile/v1/chat/invites/{$token}", $this->headers())->assertStatus(410)->assertJsonPath('error_code', 'chat_invite_expired');
        $this->postJson("/api/mobile/v1/chat/invites/{$token}/join", [], $this->headers())->assertStatus(410);

        // The admin opening the link again gets a fresh one that doesn't expire.
        $this->as($this->alice);
        $fresh = $this->getJson("/api/mobile/v1/chat/groups/{$group}/invite", $this->headers())->assertOk();
        $this->assertNotSame($token, $fresh->json('data.token'));
        $this->assertNull($fresh->json('data.expires_at'));

        $this->as($this->carol);
        $this->postJson('/api/mobile/v1/chat/invites/'.$fresh->json('data.token').'/join', [], $this->headers())->assertOk();
    }

    public function test_invite_expiry_only_takes_the_offered_steps(): void
    {
        $group = $this->group($this->alice, [$this->bob]);

        $this->as($this->alice);
        $this->postJson("/api/mobile/v1/chat/groups/{$group}/invite/reset", ['expires_in_hours' => 5], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('expires_in_hours');
        $this->postJson("/api/mobile/v1/chat/groups/{$group}/invite/reset", [], $this->headers())
            ->assertOk()->assertJsonPath('data.expires_at', null);
    }

    // ================================================================ ownership & deleting for everyone

    public function test_the_owner_hands_over_and_stays_an_admin(): void
    {
        $group = $this->group($this->alice, [$this->bob, $this->carol]);
        $this->as($this->alice);
        $bob = collect($this->getJson("/api/mobile/v1/chat/groups/{$group}/members", $this->headers())->json('data'))
            ->firstWhere('profile.account_name', 'Bob')['participant_id'];

        // Only the owner can.
        $this->as($this->carol);
        $this->postJson("/api/mobile/v1/chat/groups/{$group}/owner", ['participant_id' => $bob], $this->headers())
            ->assertForbidden()->assertJsonPath('error_code', 'chat_owner_action_only');

        $this->as($this->alice);
        $this->postJson("/api/mobile/v1/chat/groups/{$group}/owner", ['participant_id' => $bob], $this->headers())
            ->assertOk()->assertJsonPath('data.my_role', 'admin');
        $roles = collect($this->getJson("/api/mobile/v1/chat/groups/{$group}/members", $this->headers())->json('data'))->pluck('role', 'profile.account_name');
        $this->assertSame('owner', $roles['Bob']);
        $this->assertSame('admin', $roles['Alice']);
    }

    public function test_the_owner_deletes_the_group_for_everyone(): void
    {
        $group = $this->group($this->alice, [$this->bob]);

        $this->as($this->bob);
        $this->deleteJson("/api/mobile/v1/chat/groups/{$group}", [], $this->headers())->assertForbidden();

        $this->as($this->alice);
        $this->deleteJson("/api/mobile/v1/chat/groups/{$group}", [], $this->headers())->assertOk();

        $this->as($this->bob);
        $this->getJson("/api/mobile/v1/chat/conversations/{$group}", $this->headers())->assertNotFound();
        $this->assertNotContains($group, collect($this->getJson('/api/mobile/v1/chat/conversations', $this->headers())->json('data'))->pluck('id')->all());
    }

    // ================================================================ calls per country

    public function test_calls_can_be_switched_off_in_one_country(): void
    {
        ChatSetting::current()->update(['calls_disabled_countries' => [$this->egypt->id]]);

        $withOmar = $this->direct($this->alice, $this->omar);
        $this->send($this->omar, $withOmar, ['type' => 'text', 'body' => 'hi'])->assertCreated(); // accepts the request

        $this->as($this->omar);
        $this->postJson("/api/mobile/v1/chat/conversations/{$withOmar}/calls", ['type' => 'audio'], $this->headers())
            ->assertForbidden()->assertJsonPath('error_code', 'chat_calls_unavailable_country');

        $this->as($this->alice);
        $this->postJson("/api/mobile/v1/chat/conversations/{$withOmar}/calls", ['type' => 'video'], $this->headers())
            ->assertForbidden()->assertJsonPath('error_code', 'chat_calls_unavailable_peer_country');

        // The app hides the call buttons where calling can't work.
        $this->getJson("/api/mobile/v1/chat/conversations/{$withOmar}", $this->headers())->assertOk()->assertJsonPath('data.can_call', false);
        $withBob = $this->direct($this->alice, $this->bob);
        $this->getJson("/api/mobile/v1/chat/conversations/{$withBob}", $this->headers())->assertOk()->assertJsonPath('data.can_call', true);
    }

    // ================================================================ notification privacy

    public function test_notification_privacy_has_three_levels(): void
    {
        $chat = $this->direct($this->alice, $this->bob);

        // all (default): name and text.
        $this->send($this->alice, $chat, ['type' => 'text', 'body' => 'see you at 9'])->assertCreated();
        Http::assertSent(fn ($r) => $r['headings']['en'] === 'Alice' && $r['contents']['en'] === 'see you at 9');

        // name: who, but not what.
        $this->as($this->bob);
        $this->patchJson('/api/mobile/v1/chat/privacy', ['notification_privacy' => 'name'], $this->headers())
            ->assertOk()->assertJsonPath('data.notification_privacy', 'name')->assertJsonPath('data.notification_preview', false);
        $this->send($this->alice, $chat, ['type' => 'text', 'body' => 'secret plan'])->assertCreated();
        Http::assertSent(fn ($r) => $r['headings']['en'] === 'Alice' && $r['contents']['en'] === 'New message');
        Http::assertNotSent(fn ($r) => ($r['contents']['en'] ?? null) === 'secret plan');

        // none: not even who.
        $this->as($this->bob);
        $this->patchJson('/api/mobile/v1/chat/privacy', ['notification_privacy' => 'none'], $this->headers())->assertOk();
        $this->send($this->alice, $chat, ['type' => 'text', 'body' => 'another secret'])->assertCreated();
        Http::assertSent(fn ($r) => $r['headings']['en'] === 'Dorr' && $r['contents']['en'] === 'New message');
        Http::assertNotSent(fn ($r) => ($r['contents']['en'] ?? null) === 'another secret');
    }

    public function test_the_old_on_off_switch_still_works(): void
    {
        $this->as($this->bob);
        $this->patchJson('/api/mobile/v1/chat/privacy', ['notification_preview' => false], $this->headers())
            ->assertOk()->assertJsonPath('data.notification_privacy', 'none')->assertJsonPath('data.notification_preview', false);
        $this->patchJson('/api/mobile/v1/chat/privacy', ['notification_preview' => true], $this->headers())
            ->assertOk()->assertJsonPath('data.notification_privacy', 'all');
        $this->patchJson('/api/mobile/v1/chat/privacy', ['notification_privacy' => 'everything'], $this->headers())
            ->assertUnprocessable();
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

    /**
     * @param  list<User>  $members
     */
    private function group(User $owner, array $members): string
    {
        $this->as($owner);

        return $this->postJson('/api/mobile/v1/chat/groups', ['name' => 'Team', 'members' => array_map(fn (User $u) => $u->id, $members)], $this->headers())
            ->assertCreated()->json('data.conversation.id');
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
