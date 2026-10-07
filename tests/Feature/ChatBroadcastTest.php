<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Chat\Models\ChatContact;
use Modules\Chat\Models\ChatMessage;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * Broadcast lists (like WhatsApp's): one message, each person gets it in our own chat — only the
 * ones who have me saved.
 */
class ChatBroadcastTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    private User $carol;

    private User $dave;

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
        $this->dave = $make('Dave', '+966500000004');

        // Alice has all three; Bob and Carol saved her back, Dave didn't.
        foreach ([[$this->alice, $this->bob], [$this->alice, $this->carol], [$this->alice, $this->dave], [$this->bob, $this->alice], [$this->carol, $this->alice]] as [$owner, $contact]) {
            ChatContact::query()->create(['owner_type' => 'user', 'owner_id' => $owner->id, 'name' => $contact->name, 'phone' => $contact->phone, 'contact_type' => 'user', 'contact_id' => $contact->id, 'source' => 'manual']);
        }
    }

    public function test_one_message_lands_in_each_chat_of_the_people_who_saved_me(): void
    {
        $this->as($this->alice);
        $list = $this->postJson('/api/mobile/v1/chat/broadcasts', ['name' => 'Customers', 'members' => [$this->bob->id, $this->carol->id, $this->dave->id]], $this->headers())
            ->assertCreated()->assertJsonPath('data.members_count', 3)->json('data.id');

        $this->postJson("/api/mobile/v1/chat/broadcasts/{$list}/messages", ['type' => 'text', 'body' => 'New offers this week!'], $this->headers())
            ->assertCreated()->assertJsonPath('data.sent', 2)
            ->assertJsonCount(1, 'data.skipped')->assertJsonPath('data.skipped.0.name', 'Dave');

        // Bob and Carol each have it in their own chat with Alice; Dave has nothing.
        foreach ([$this->bob, $this->carol] as $who) {
            $this->as($who);
            $chats = $this->getJson('/api/mobile/v1/chat/conversations', $this->headers())->assertOk()->json('data');
            $this->assertCount(1, $chats);
            $this->assertSame('New offers this week!', $chats[0]['last_message']['body']);
            $this->assertSame('Alice', $chats[0]['title']);
        }
        $this->as($this->dave);
        $this->assertCount(0, $this->getJson('/api/mobile/v1/chat/conversations', $this->headers())->json('data'));

        // The list keeps what I sent.
        $this->as($this->alice);
        $this->getJson("/api/mobile/v1/chat/broadcasts/{$list}", $this->headers())->assertOk()
            ->assertJsonPath('data.sent.0.body', 'New offers this week!')->assertJsonPath('data.sent.0.sent_count', 2);
        $this->getJson('/api/mobile/v1/chat/broadcasts', $this->headers())->assertOk()->assertJsonPath('data.0.last_sent.body', 'New offers this week!');
    }

    public function test_a_photo_is_uploaded_once_and_copied_to_everyone(): void
    {
        $this->as($this->alice);
        $list = $this->postJson('/api/mobile/v1/chat/broadcasts', ['members' => [$this->bob->id, $this->carol->id]], $this->headers())
            ->assertCreated()->assertJsonPath('data.title', 'Bob، Carol')->json('data.id');

        $this->post("/api/mobile/v1/chat/broadcasts/{$list}/messages", ['type' => 'image', 'body' => 'menu', 'files' => [UploadedFile::fake()->image('menu.jpg', 300, 300)]], $this->headers() + ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.sent', 2);

        $images = ChatMessage::query()->where('type', 'image')->get();
        $this->assertCount(2, $images);
        $images->each(fn ($m) => $this->assertNotNull($m->getFirstMedia(ChatMessage::ATTACHMENTS)));
    }

    public function test_only_mine_and_people_can_change(): void
    {
        $this->as($this->alice);
        $list = $this->postJson('/api/mobile/v1/chat/broadcasts', ['name' => 'VIP', 'members' => [$this->bob->id]], $this->headers())->json('data.id');
        $this->patchJson("/api/mobile/v1/chat/broadcasts/{$list}", ['members' => [$this->bob->id, $this->carol->id], 'name' => null], $this->headers())
            ->assertOk()->assertJsonPath('data.members_count', 2)->assertJsonPath('data.name', null);

        $this->as($this->bob);
        $this->getJson("/api/mobile/v1/chat/broadcasts/{$list}", $this->headers())->assertNotFound();
        $this->postJson("/api/mobile/v1/chat/broadcasts/{$list}/messages", ['type' => 'text', 'body' => 'hi'], $this->headers())->assertNotFound();

        $this->as($this->alice);
        $this->postJson("/api/mobile/v1/chat/broadcasts/{$list}/messages", ['type' => 'poll', 'body' => 'x', 'options' => ['a', 'b']], $this->headers())
            ->assertUnprocessable();
        $this->deleteJson("/api/mobile/v1/chat/broadcasts/{$list}", [], $this->headers())->assertOk();
        $this->getJson('/api/mobile/v1/chat/broadcasts', $this->headers())->assertJsonCount(0, 'data');
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
