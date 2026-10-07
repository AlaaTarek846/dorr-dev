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
use Modules\Chat\Models\ChatContact;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Services\ChatPushNotifier;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * Silent messages, "note to self" and formatting marks kept out of notifications.
 */
class ChatEssentialsTest extends TestCase
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

    // ================================================================ silent

    public function test_a_silent_message_is_flagged_for_the_app_to_mute(): void
    {
        $chat = $this->direct($this->alice, $this->bob);

        $this->send($this->alice, $chat, ['type' => 'text', 'body' => 'late night', 'silent' => true])
            ->assertCreated()->assertJsonPath('data.is_silent', true);

        Http::assertSent(fn ($r) => $r['include_player_ids'] === ['bob-phone'] && $r['data']['silent'] === '1');
    }

    public function test_a_normal_message_is_not_silent(): void
    {
        $chat = $this->direct($this->alice, $this->bob);

        $this->send($this->alice, $chat, ['type' => 'text', 'body' => 'hi'])
            ->assertCreated()->assertJsonPath('data.is_silent', false);

        Http::assertSent(fn ($r) => $r['data']['silent'] === '0');
    }

    public function test_silent_also_works_from_a_multipart_upload(): void
    {
        $chat = $this->direct($this->alice, $this->bob);

        // The app sends files as multipart, where a flag is the text "1".
        $this->send($this->alice, $chat, ['type' => 'text', 'body' => 'shh', 'silent' => '1'])
            ->assertCreated()->assertJsonPath('data.is_silent', true);
    }

    // ================================================================ formatting

    public function test_the_notification_shows_the_text_without_formatting_marks(): void
    {
        $chat = $this->direct($this->alice, $this->bob);

        $this->send($this->alice, $chat, ['type' => 'text', 'body' => '*Meeting* at _9_, ~not 8~, room `B2`'])->assertCreated();

        // Stored as typed (the apps draw the formatting) — only the notification is plain.
        $this->assertSame('*Meeting* at _9_, ~not 8~, room `B2`', ChatMessage::query()->latest('id')->value('body'));
        Http::assertSent(fn ($r) => $r['contents']['en'] === 'Meeting at 9, not 8, room B2');
    }

    public function test_list_and_quote_lines_read_cleanly_in_the_notification(): void
    {
        $this->assertSame("Shopping:\n• milk\n• bread\n1. eggs\nshe said so", ChatPushNotifier::plain("Shopping:\n- milk\n* *bread*\n1. eggs\n> she said so"));
        // A dash inside a line, or "-5", isn't a list.
        $this->assertSame('-5 degrees - cold', ChatPushNotifier::plain('-5 degrees - cold'));
    }

    public function test_read_later_is_my_own_list_apart_from_stars(): void
    {
        $chat = $this->direct($this->alice, $this->bob);
        $first = $this->send($this->bob, $chat, ['type' => 'text', 'body' => 'the long article'])->json('data.id');
        $second = $this->send($this->bob, $chat, ['type' => 'text', 'body' => 'the recipe'])->json('data.id');

        $this->as($this->alice);
        $this->putJson("/api/mobile/v1/chat/messages/{$first}/read-later", ['on' => true], $this->headers())->assertOk();
        $this->putJson("/api/mobile/v1/chat/messages/{$second}/read-later", ['on' => true], $this->headers())->assertOk();

        // Oldest first, flagged on the message, and not starred by it.
        $this->getJson('/api/mobile/v1/chat/messages/read-later', $this->headers())->assertOk()
            ->assertJsonPath('data.0.id', $first)->assertJsonPath('data.1.id', $second)
            ->assertJsonPath('data.0.is_read_later', true)->assertJsonPath('data.0.is_starred', false);
        $this->getJson('/api/mobile/v1/chat/messages/starred', $this->headers())->assertOk()->assertJsonCount(0, 'data');

        // Done with one; Bob's list is his own.
        $this->putJson("/api/mobile/v1/chat/messages/{$first}/read-later", ['on' => false], $this->headers())->assertOk();
        $this->getJson('/api/mobile/v1/chat/messages/read-later', $this->headers())->assertOk()->assertJsonCount(1, 'data');
        $this->as($this->bob);
        $this->getJson('/api/mobile/v1/chat/messages/read-later', $this->headers())->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_marks_inside_words_and_maths_are_left_alone(): void
    {
        $this->assertSame('price 5*3*2 = 30', ChatPushNotifier::plain('price 5*3*2 = 30'));
        $this->assertSame('snake_case_name', ChatPushNotifier::plain('snake_case_name'));
        // Mid-line "* x *" isn't bold (at the start of a line "* " is a bullet — see the list test).
        $this->assertSame('a * spaced *', ChatPushNotifier::plain('a * spaced *'));
        $this->assertSame('x = 1', ChatPushNotifier::plain('```x = 1```'));
        $this->assertSame('(bold)', ChatPushNotifier::plain('(*bold*)'));
    }

    // ================================================================ note to self

    public function test_note_to_self_is_one_chat_with_only_me_in_it(): void
    {
        $this->as($this->alice);
        $first = $this->postJson('/api/mobile/v1/chat/conversations/self', [], $this->headers())->assertOk()
            ->assertJsonPath('data.is_self', true)
            ->assertJsonPath('data.title', __('chat.self_title'))
            ->assertJsonPath('data.peer', null)
            ->assertJsonPath('data.can_send', true)
            ->json('data.id');

        // The same chat every time.
        $again = $this->postJson('/api/mobile/v1/chat/conversations/self', [], $this->headers())->assertOk()->json('data.id');
        $this->assertSame($first, $again);

        $conversation = ChatConversation::query()->where('uuid', $first)->firstOrFail();
        $this->assertTrue($conversation->isSelf());
        $this->assertSame(1, $conversation->participants()->count());

        // Bob can't get in.
        $this->as($this->bob);
        $this->getJson("/api/mobile/v1/chat/conversations/{$first}", $this->headers())->assertForbidden();
    }

    public function test_a_note_to_self_is_saved_without_notifying_anyone(): void
    {
        $this->as($this->alice);
        $chat = $this->postJson('/api/mobile/v1/chat/conversations/self', [], $this->headers())->json('data.id');

        $this->send($this->alice, $chat, ['type' => 'text', 'body' => 'buy milk'])->assertCreated()
            ->assertJsonPath('data.is_mine', true);

        $this->as($this->alice);
        $this->getJson("/api/mobile/v1/chat/conversations/{$chat}/messages", $this->headers())->assertOk()
            ->assertJsonPath('data.messages.0.body', 'buy milk');

        // It shows in my chat list, and nobody got a push.
        $this->getJson('/api/mobile/v1/chat/conversations', $this->headers())->assertOk()
            ->assertJsonFragment(['id' => $chat, 'is_self' => true]);
        Http::assertNothingSent();
    }

    public function test_a_direct_chat_is_not_a_self_chat(): void
    {
        $chat = $this->direct($this->alice, $this->bob);

        $this->as($this->alice);
        $this->getJson("/api/mobile/v1/chat/conversations/{$chat}", $this->headers())->assertOk()
            ->assertJsonPath('data.is_self', false)
            ->assertJsonPath('data.title', 'Bob');
    }

    public function test_you_cant_call_yourself(): void
    {
        config(['chat.livekit.url' => 'wss://x', 'chat.livekit.api_key' => 'k', 'chat.livekit.api_secret' => 's']);
        $this->as($this->alice);
        $chat = $this->postJson('/api/mobile/v1/chat/conversations/self', [], $this->headers())->json('data.id');

        $this->postJson("/api/mobile/v1/chat/conversations/{$chat}/calls", ['type' => 'audio'], $this->headers())
            ->assertStatus(422)->assertJsonPath('error_code', 'chat_self_no_calls');
    }

    // ================================================================ edit / delete deadlines

    public function test_my_message_says_until_when_it_can_be_edited_and_deleted(): void
    {
        $chat = $this->direct($this->alice, $this->bob);
        $id = $this->send($this->alice, $chat, ['type' => 'text', 'body' => 'oops'])->assertCreated()
            ->assertJsonPath('data.edit_until', fn ($v) => $v !== null)
            ->assertJsonPath('data.delete_until', fn ($v) => $v !== null)
            ->json('data.id');

        // Bob sees no deadlines on Alice's message.
        $this->as($this->bob);
        $this->getJson("/api/mobile/v1/chat/conversations/{$chat}/messages", $this->headers())->assertOk()
            ->assertJsonPath('data.messages.0.edit_until', null)
            ->assertJsonPath('data.messages.0.delete_until', null);

        // After the edit window (15 min by default) only "delete for everyone" is still open…
        $this->travel(20)->minutes();
        $this->as($this->alice);
        $this->getJson("/api/mobile/v1/chat/conversations/{$chat}/messages", $this->headers())->assertOk()
            ->assertJsonPath('data.messages.0.edit_until', null)
            ->assertJsonPath('data.messages.0.delete_until', fn ($v) => $v !== null);

        // …and after that window too, nothing — the app hides both, and the server agrees.
        $this->travel(3)->days();
        $this->getJson("/api/mobile/v1/chat/conversations/{$chat}/messages", $this->headers())->assertOk()
            ->assertJsonPath('data.messages.0.delete_until', null);
        $this->deleteJson("/api/mobile/v1/chat/messages/{$id}", [], $this->headers())->assertStatus(422)
            ->assertJsonPath('data', []);
    }

    // ================================================================ my own chat look

    public function test_my_own_wallpaper_and_colours_for_one_chat_only_i_see_them(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $default = \Modules\Chat\Models\ChatTheme::query()->create(['sender_color' => '#111111', 'receiver_color' => '#EEEEEE', 'is_default' => true]);
        app(\Modules\Chat\Services\ChatThemeService::class)->flush();
        $chat = $this->direct($this->alice, $this->bob);

        // A picture from my gallery: drawn over the default theme, dimmed a little, its colours kept.
        $this->as($this->alice);
        $applied = $this->post("/api/mobile/v1/chat/conversations/{$chat}/wallpaper", ['image' => \Illuminate\Http\UploadedFile::fake()->image('beach.jpg', 1080, 1920)], $this->headers() + ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.theme.applied.is_custom', true)
            ->assertJsonPath('data.theme.applied.dim', \Modules\Chat\Services\ChatThemeService::DEFAULT_DIM)
            ->assertJsonPath('data.theme.applied.sender_color', '#111111')
            ->json('data.theme.applied');
        $this->assertStringContainsString('/storage/chat/wallpapers/', $applied['wallpaper']);
        $first = \Modules\Chat\Models\ChatParticipant::query()->where('participant_id', $this->alice->id)->latest('id')->first()->custom_theme['wallpaper_path'];
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($first);

        // My bubble colours and a darker dim; the picture stays.
        $this->patchJson("/api/mobile/v1/chat/conversations/{$chat}/settings", ['custom_theme' => ['sender_color' => '#7C3AED', 'receiver_color' => '#FDE68A', 'dim' => 40]], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.theme.applied.sender_color', '#7C3AED')
            ->assertJsonPath('data.theme.applied.receiver_color', '#FDE68A')
            ->assertJsonPath('data.theme.applied.dim', 40)
            ->assertJsonPath('data.theme.custom.sender_color', '#7C3AED')
            ->assertJsonPath('data.theme.applied.wallpaper', $applied['wallpaper']);

        // Bob's side of the same chat is untouched.
        $this->as($this->bob);
        $this->getJson("/api/mobile/v1/chat/conversations/{$chat}", $this->headers())->assertOk()
            ->assertJsonPath('data.theme.applied.id', $default->id)
            ->assertJsonPath('data.theme.custom', null);

        // A new picture replaces the old one (deleted from storage).
        $this->as($this->alice);
        $this->post("/api/mobile/v1/chat/conversations/{$chat}/wallpaper", ['image' => \Illuminate\Http\UploadedFile::fake()->image('city.png', 800, 1600)], $this->headers() + ['Accept' => 'application/json'])->assertOk();
        \Illuminate\Support\Facades\Storage::disk('public')->assertMissing($first);
        $second = \Modules\Chat\Models\ChatParticipant::query()->where('participant_id', $this->alice->id)->latest('id')->first()->custom_theme['wallpaper_path'];

        // Remove just the picture: the colours stay.
        $this->patchJson("/api/mobile/v1/chat/conversations/{$chat}/settings", ['custom_theme' => ['wallpaper' => null]], $this->headers())->assertOk()
            ->assertJsonPath('data.theme.custom.wallpaper', null)
            ->assertJsonPath('data.theme.applied.sender_color', '#7C3AED');
        \Illuminate\Support\Facades\Storage::disk('public')->assertMissing($second);

        // "Back to the theme": my look is gone entirely.
        $this->patchJson("/api/mobile/v1/chat/conversations/{$chat}/settings", ['custom_theme' => null], $this->headers())->assertOk()
            ->assertJsonPath('data.theme.custom', null)
            ->assertJsonPath('data.theme.applied.id', $default->id);
    }

    public function test_the_custom_look_is_validated(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $chat = $this->direct($this->alice, $this->bob);
        $this->as($this->alice);

        $this->patchJson("/api/mobile/v1/chat/conversations/{$chat}/settings", ['custom_theme' => ['sender_color' => 'red']], $this->headers())->assertStatus(422);
        $this->patchJson("/api/mobile/v1/chat/conversations/{$chat}/settings", ['custom_theme' => ['dim' => 95]], $this->headers())->assertStatus(422);
        // A wallpaper only arrives as an upload, never as a link to anything.
        $this->patchJson("/api/mobile/v1/chat/conversations/{$chat}/settings", ['custom_theme' => ['wallpaper' => 'https://evil.example/x.jpg']], $this->headers())->assertStatus(422);
        $this->post("/api/mobile/v1/chat/conversations/{$chat}/wallpaper", ['image' => \Illuminate\Http\UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')], $this->headers() + ['Accept' => 'application/json'])->assertStatus(422);

        // No theme at all + only colours: drawn on Dorr's look with my colours.
        $this->patchJson("/api/mobile/v1/chat/conversations/{$chat}/settings", ['custom_theme' => ['background_color' => '#0F172A']], $this->headers())->assertOk()
            ->assertJsonPath('data.theme.applied.is_custom', true)
            ->assertJsonPath('data.theme.applied.is_dark', true)
            ->assertJsonPath('data.theme.applied.wallpaper', null);
    }

    // ================================================================ my stickers

    public function test_a_sticker_made_from_my_photo_is_kept_and_sent(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $chat = $this->direct($this->alice, $this->bob);
        $this->as($this->alice);

        $made = $this->post('/api/mobile/v1/chat/stickers/mine', ['image' => \Illuminate\Http\UploadedFile::fake()->image('me.png', 512, 512), 'emoji' => '😄'], $this->headers() + ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.width', 512)->json('data');

        $this->getJson('/api/mobile/v1/chat/stickers', $this->headers())->assertOk()
            ->assertJsonPath('data.mine.0.id', $made['id'])
            ->assertJsonPath('data.mine.0.emoji', '😄');

        $sent = $this->send($this->alice, $chat, ['type' => 'sticker', 'my_sticker_id' => $made['id']])->assertCreated()
            ->assertJsonPath('data.meta.source', 'mine')
            ->assertJsonPath('data.meta.url', $made['url'])
            ->json('data.id');

        // Bob sees it, but can't send Alice's sticker as his own.
        $this->send($this->bob, $chat, ['type' => 'sticker', 'my_sticker_id' => $made['id']])->assertStatus(422)
            ->assertJsonPath('error_code', 'chat_sticker_not_found');

        // Removing it from "My stickers" doesn't break the message that already carries it.
        $this->as($this->alice);
        $this->deleteJson("/api/mobile/v1/chat/stickers/mine/{$made['id']}", [], $this->headers())->assertOk();
        $this->getJson('/api/mobile/v1/chat/stickers', $this->headers())->assertOk()->assertJsonCount(0, 'data.mine');
        $this->getJson("/api/mobile/v1/chat/conversations/{$chat}/messages", $this->headers())->assertOk()
            ->assertJsonPath('data.messages.0.id', $sent)
            ->assertJsonPath('data.messages.0.meta.url', $made['url']);
    }

    public function test_my_sticker_must_be_a_small_transparent_image(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $this->as($this->alice);
        $post = fn ($file) => $this->post('/api/mobile/v1/chat/stickers/mine', ['image' => $file], $this->headers() + ['Accept' => 'application/json']);

        $post(\Illuminate\Http\UploadedFile::fake()->image('photo.jpg', 512, 512))->assertStatus(422);      // no transparency
        $post(\Illuminate\Http\UploadedFile::fake()->image('huge.png', 3000, 3000))->assertStatus(422);     // too big
        $post(\Illuminate\Http\UploadedFile::fake()->image('ok.webp', 512, 512))->assertCreated();
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

        return $this->post("/api/mobile/v1/chat/conversations/{$conversation}/messages", $data, $this->headers() + ['Accept' => 'application/json']);
    }
}
