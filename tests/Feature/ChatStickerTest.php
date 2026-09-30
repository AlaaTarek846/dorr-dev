<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\Chat\Models\ChatContact;
use Modules\User\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Stickers & GIFs: the Giphy library (GIFs + animated stickers) and Dorr's own sticker packs
 * made by the admin. A sent one is built on the server from an id — never a client URL.
 */
class ChatStickerTest extends TestCase
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

        $this->alice = User::create(['name' => 'Alice', 'phone' => '+966500000001', 'country_id' => $saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->bob = User::create(['name' => 'Bob', 'phone' => '+966500000002', 'country_id' => $saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        ChatContact::query()->create(['owner_type' => 'user', 'owner_id' => $this->bob->id, 'name' => 'Alice', 'phone' => $this->alice->phone, 'contact_type' => 'user', 'contact_id' => $this->alice->id, 'source' => 'manual']);

        Storage::fake('public');
        config(['chat.giphy.key' => 'test-key']);
    }

    public function test_the_gif_library_is_browsed_and_a_gif_is_sent_by_id(): void
    {
        Http::fake([
            'api.giphy.com/v1/gifs/trending*' => Http::response(['data' => [$this->giphyRow('abc123')], 'pagination' => ['total_count' => 100]]),
            'api.giphy.com/v1/stickers/search*' => Http::response(['data' => [$this->giphyRow('stk999')], 'pagination' => ['total_count' => 1]]),
            'api.giphy.com/v1/gifs/abc123*' => Http::response(['data' => $this->giphyRow('abc123')]),
            'api.giphy.com/v1/gifs/nope000*' => Http::response(['data' => []], 404),
        ]);

        $this->as($this->alice);
        $this->getJson('/api/mobile/v1/chat/gifs', $this->headers())->assertOk()
            ->assertJsonPath('data.items.0.id', 'abc123')->assertJsonPath('data.items.0.kind', 'gif')->assertJsonPath('data.next_offset', 1);
        $this->getJson('/api/mobile/v1/chat/gifs?kind=stickers&q=happy', $this->headers())->assertOk()->assertJsonPath('data.items.0.kind', 'sticker');

        $chat = $this->postJson('/api/mobile/v1/chat/conversations/direct', ['participant_id' => $this->bob->id], $this->headers())->json('data.id');
        $this->postJson("/api/mobile/v1/chat/conversations/{$chat}/messages", ['type' => 'gif', 'giphy_id' => 'abc123'], $this->headers())
            ->assertCreated()->assertJsonPath('data.type', 'gif')
            ->assertJsonPath('data.meta.url', 'https://media.giphy.com/media/abc123/giphy.gif')
            ->assertJsonPath('data.meta.source', 'giphy');

        // An id Giphy doesn't know is refused — and a client URL is never taken.
        $this->postJson("/api/mobile/v1/chat/conversations/{$chat}/messages", ['type' => 'gif', 'giphy_id' => 'nope000', 'url' => 'https://evil.example/x.gif'], $this->headers())
            ->assertStatus(422)->assertJsonPath('error_code', 'chat_gif_not_found');
    }

    public function test_without_a_key_the_library_is_empty_but_packs_still_work(): void
    {
        config(['chat.giphy.key' => '']);
        Http::fake();

        $this->as($this->alice);
        $this->getJson('/api/mobile/v1/chat/stickers', $this->headers())->assertOk()->assertJsonPath('data.library_enabled', false);
        $this->getJson('/api/mobile/v1/chat/gifs', $this->headers())->assertOk()->assertJsonCount(0, 'data.items');
        Http::assertNothingSent();
    }

    public function test_admin_sticker_packs_are_picked_and_sent(): void
    {
        $admin = Admin::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'secret123', 'status' => 'active']);
        foreach (['chat-stickers.view', 'chat-stickers.create', 'chat-stickers.update', 'chat-stickers.delete'] as $p) {
            Permission::findOrCreate($p, 'admin_api');
        }
        $admin->givePermissionTo(['chat-stickers.view', 'chat-stickers.create', 'chat-stickers.update', 'chat-stickers.delete']);
        Sanctum::actingAs($admin, [], 'admin_api');

        $pack = $this->post('/api/admin/v1/chat-sticker-packs', ['translations' => [['locale' => 'en', 'name' => 'Dorr Cats']]], ['Accept' => 'application/json'])
            ->assertCreated()->json('data.id');
        $stickers = $this->post("/api/admin/v1/chat-sticker-packs/{$pack}/stickers", [
            'files' => [UploadedFile::fake()->image('a.png', 256, 256), UploadedFile::fake()->image('b.png', 256, 256)], 'emoji' => '😺',
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonCount(2, 'data.stickers')->json('data.stickers');

        $this->as($this->alice);
        $this->getJson('/api/mobile/v1/chat/stickers', $this->headers())->assertOk()
            ->assertJsonPath('data.packs.0.name', 'Dorr Cats')->assertJsonCount(2, 'data.packs.0.stickers');

        $chat = $this->postJson('/api/mobile/v1/chat/conversations/direct', ['participant_id' => $this->bob->id], $this->headers())->json('data.id');
        $this->postJson("/api/mobile/v1/chat/conversations/{$chat}/messages", ['type' => 'sticker', 'sticker_id' => $stickers[0]['id']], $this->headers())
            ->assertCreated()->assertJsonPath('data.meta.source', 'pack')->assertJsonPath('data.meta.emoji', '😺')->assertJsonPath('data.meta.width', 256);

        // A hidden pack can't be sent from any more.
        Sanctum::actingAs($admin, [], 'admin_api');
        $this->patchJson("/api/admin/v1/chat-sticker-packs/{$pack}/status", ['status' => false])->assertOk();
        $this->as($this->alice);
        $this->getJson('/api/mobile/v1/chat/stickers', $this->headers())->assertJsonCount(0, 'data.packs');
        $this->postJson("/api/mobile/v1/chat/conversations/{$chat}/messages", ['type' => 'sticker', 'sticker_id' => $stickers[1]['id']], $this->headers())
            ->assertStatus(422)->assertJsonPath('error_code', 'chat_sticker_not_found');
    }

    /**
     * @return array<string, mixed>
     */
    private function giphyRow(string $id): array
    {
        return [
            'id' => $id,
            'title' => 'Happy dance',
            'images' => [
                'downsized_medium' => ['url' => "https://media.giphy.com/media/{$id}/giphy.gif", 'width' => '480', 'height' => '270'],
                'fixed_width' => ['url' => "https://media.giphy.com/media/{$id}/200w.gif", 'webp' => "https://media.giphy.com/media/{$id}/200w.webp", 'mp4' => "https://media.giphy.com/media/{$id}/200w.mp4", 'width' => '200', 'height' => '113'],
                'fixed_width_downsampled' => ['url' => "https://media.giphy.com/media/{$id}/200w_d.gif", 'webp' => "https://media.giphy.com/media/{$id}/200w_d.webp"],
            ],
        ];
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
