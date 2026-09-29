<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use App\Models\NotificationDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\Chat\Events\ChatRealtimeEvent;
use Modules\Chat\Models\ChatCall;
use Modules\Chat\Models\ChatContact;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Models\ChatSetting;
use Modules\User\Models\User;
use Modules\Wallet\Database\Seeders\FinancialCategorySeeder;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Services\WalletService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The chat module (docs/chat-plan.md): direct chats + message requests, ticks, privacy and
 * blocks, editing / deleting / replying / reacting, groups and roles, contacts (sync, lookup,
 * QR), wallet cards, disappearing messages, calls (LiveKit) and the admin limits.
 */
class ChatTest extends TestCase
{
    use RefreshDatabase;

    private Country $saudi;

    private User $alice;

    private User $bob;

    private User $carol;

    protected function setUp(): void
    {
        parent::setUp();

        $sar = Currency::create(['code' => 'SAR', 'symbol' => 'SAR', 'decimal_places' => 2]);
        $flag = Flag::create(['code' => 'sa']);
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => $sar->id, 'status' => true]);
        Language::create(['code' => 'en', 'direction' => 'ltr', 'is_default_website' => true, 'is_default_dashboard' => true, 'stores_translation' => true, 'status' => true, 'flag_id' => $flag->id]);

        $this->alice = $this->makeUser('Alice', '+966500000001');
        $this->bob = $this->makeUser('Bob', '+966500000002');
        $this->carol = $this->makeUser('Carol', '+966500000003');

        Storage::fake('public');
    }

    // ================================================================ direct chats & requests

    public function test_a_first_message_from_a_stranger_is_a_request_and_replying_accepts_it(): void
    {
        $conversation = $this->openDirect($this->alice, $this->bob);
        $this->assertSame('pending', $conversation['status']);

        $this->sendText($this->alice, $conversation['id'], 'Hi Bob')->assertCreated();

        // Bob: not in his chat list, but in his requests (with a badge count).
        $this->as($this->bob);
        $list = $this->getJson('/api/mobile/v1/chat/conversations', $this->headers())->assertOk();
        $this->assertCount(0, $list->json('data'));
        $this->assertSame(1, $list->json('requests_count'));

        $requests = $this->getJson('/api/mobile/v1/chat/conversations?filter=requests', $this->headers())->assertOk();
        $this->assertCount(1, $requests->json('data'));
        $this->assertTrue($requests->json('data.0.is_request'));

        // Alice (the sender) sees it in her list.
        $this->as($this->alice);
        $this->assertCount(1, $this->getJson('/api/mobile/v1/chat/conversations', $this->headers())->json('data'));

        // Bob replies → accepted, and it moves to his list.
        $this->sendText($this->bob, $conversation['id'], 'Hello!')->assertCreated();
        $this->assertSame('accepted', ChatConversation::query()->where('uuid', $conversation['id'])->value('status')->value);
        $this->as($this->bob);
        $this->assertCount(1, $this->getJson('/api/mobile/v1/chat/conversations', $this->headers())->json('data'));
    }

    public function test_a_contact_chats_directly_and_ticks_go_sent_delivered_read(): void
    {
        $this->saveContact($this->bob, $this->alice);
        $conversation = $this->openDirect($this->alice, $this->bob);
        $this->assertSame('accepted', $conversation['status']);

        $sent = $this->sendText($this->alice, $conversation['id'], 'Hi')->assertCreated();
        $this->assertSame('sent', $sent->json('data.status'));
        $this->assertTrue($sent->json('data.is_mine'));

        $this->as($this->bob);
        $row = $this->getJson('/api/mobile/v1/chat/conversations', $this->headers())->json('data.0');
        $this->assertSame(1, $row['unread_count']);
        $this->assertSame('Alice', $row['title']);

        $this->postJson('/api/mobile/v1/chat/conversations/delivered', [], $this->headers())->assertOk();
        $this->assertSame('delivered', $this->messagesAs($this->alice, $conversation['id'])->json('data.messages.0.status'));

        $this->as($this->bob);
        $this->postJson("/api/mobile/v1/chat/conversations/{$conversation['id']}/read", [], $this->headers())->assertOk()->assertJsonPath('data.unread_count', 0);
        $this->assertSame('read', $this->messagesAs($this->alice, $conversation['id'])->json('data.messages.0.status'));
    }

    public function test_read_receipts_off_on_either_side_hides_blue_ticks(): void
    {
        $this->saveContact($this->bob, $this->alice);
        $conversation = $this->openDirect($this->alice, $this->bob);
        $this->sendText($this->alice, $conversation['id'], 'Hi')->assertCreated();

        $this->as($this->bob);
        $this->patchJson('/api/mobile/v1/chat/privacy', ['read_receipts' => false], $this->headers())->assertOk();
        $this->postJson("/api/mobile/v1/chat/conversations/{$conversation['id']}/read", [], $this->headers())->assertOk();

        $this->assertSame('delivered', $this->messagesAs($this->alice, $conversation['id'])->json('data.messages.0.status'));
    }

    public function test_the_contact_saved_name_is_shown_instead_of_the_account_name(): void
    {
        $this->as($this->bob);
        $this->postJson('/api/mobile/v1/chat/contacts', ['name' => 'My sister', 'phone' => '0500000001'], $this->headers())->assertCreated();

        $conversation = $this->openDirect($this->bob, $this->alice);
        $this->assertSame('My sister', $conversation['title']);
    }

    public function test_sending_the_same_uuid_twice_creates_one_message(): void
    {
        $conversation = $this->openDirect($this->alice, $this->bob);
        $uuid = '4b8f3c2e-1d6a-4f7b-9a1e-2c3d4e5f6a7b';

        $first = $this->sendText($this->alice, $conversation['id'], 'Once', ['uuid' => $uuid])->assertCreated();
        $second = $this->sendText($this->alice, $conversation['id'], 'Once', ['uuid' => $uuid])->assertCreated();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, ChatMessage::query()->where('uuid', $uuid)->count());
    }

    public function test_messages_are_broadcast_to_every_participant(): void
    {
        Event::fake([ChatRealtimeEvent::class]);

        $conversation = $this->openDirect($this->alice, $this->bob);
        $this->sendText($this->alice, $conversation['id'], 'Realtime')->assertCreated();

        Event::assertDispatched(ChatRealtimeEvent::class, function (ChatRealtimeEvent $e) {
            return $e->event === 'chat.message.sent'
                && in_array('Modules.User.Models.User.'.$this->bob->id, $e->channels, true)
                && in_array('Modules.User.Models.User.'.$this->alice->id, $e->channels, true)
                && $e->payload['message']['body'] === 'Realtime';
        });
    }

    public function test_realtime_config_gives_the_app_public_values_only(): void
    {
        config([
            'broadcasting.default' => 'pusher',
            'broadcasting.connections.pusher.key' => 'public-key',
            'broadcasting.connections.pusher.secret' => 'top-secret',
            'broadcasting.connections.pusher.options.cluster' => 'eu',
            'broadcasting.connections.pusher.options.host' => 'api-eu.pusher.com',
        ]);

        $this->as($this->alice);
        $response = $this->getJson('/api/mobile/v1/chat/realtime-config', $this->headers())->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.key', 'public-key')
            ->assertJsonPath('data.cluster', 'eu')
            ->assertJsonPath('data.host', null) // Pusher cloud: the client derives it from the cluster
            ->assertJsonPath('data.auth_path', '/broadcasting/auth');

        $this->assertStringNotContainsString('top-secret', $response->getContent());
    }

    // ================================================================ privacy & blocks

    public function test_a_block_closes_the_chat_both_ways(): void
    {
        $conversation = $this->openDirect($this->alice, $this->bob);
        $this->sendText($this->alice, $conversation['id'], 'Hi')->assertCreated();

        $this->as($this->bob);
        $this->postJson('/api/mobile/v1/chat/blocks', ['participant_id' => $this->alice->id], $this->headers())->assertOk();

        $this->sendText($this->alice, $conversation['id'], 'Still there?')->assertStatus(403)->assertJsonPath('error_code', 'chat_blocked');
        $this->sendText($this->bob, $conversation['id'], 'Bye')->assertStatus(403);

        $this->as($this->bob);
        $this->postJson('/api/mobile/v1/chat/blocks/remove', ['participant_id' => $this->alice->id], $this->headers())->assertOk();
        $this->sendText($this->alice, $conversation['id'], 'Hi again')->assertCreated();
    }

    public function test_contacts_only_privacy_refuses_strangers(): void
    {
        $this->as($this->bob);
        $this->patchJson('/api/mobile/v1/chat/privacy', ['who_can_message' => 'contacts'], $this->headers())->assertOk();

        $this->as($this->alice);
        $this->postJson('/api/mobile/v1/chat/conversations/direct', ['participant_id' => $this->bob->id], $this->headers())
            ->assertStatus(403)->assertJsonPath('error_code', 'chat_not_allowed_to_message');

        $this->saveContact($this->bob, $this->alice);
        $this->assertSame('accepted', $this->openDirect($this->alice, $this->bob)['status']);
    }

    // ================================================================ editing, deleting, replying

    public function test_edit_only_own_messages_within_the_admin_window(): void
    {
        $conversation = $this->openDirect($this->alice, $this->bob);
        $id = $this->sendText($this->alice, $conversation['id'], 'Helo')->json('data.id');

        $this->as($this->bob);
        $this->patchJson("/api/mobile/v1/chat/messages/{$id}", ['body' => 'hacked'], $this->headers())->assertStatus(403);

        $this->as($this->alice);
        $this->patchJson("/api/mobile/v1/chat/messages/{$id}", ['body' => 'Hello'], $this->headers())
            ->assertOk()->assertJsonPath('data.body', 'Hello')->assertJsonPath('data.is_edited', true);

        $this->travel(16)->minutes();
        $this->patchJson("/api/mobile/v1/chat/messages/{$id}", ['body' => 'Too late'], $this->headers())
            ->assertStatus(422)->assertJsonPath('error_code', 'chat_edit_window_passed');
    }

    public function test_delete_for_everyone_hides_content_and_delete_for_me_hides_only_for_me(): void
    {
        $conversation = $this->openDirect($this->alice, $this->bob);
        $a = $this->sendText($this->alice, $conversation['id'], 'Secret')->json('data.id');
        $b = $this->sendText($this->alice, $conversation['id'], 'Keep')->json('data.id');

        $this->as($this->alice);
        $this->deleteJson("/api/mobile/v1/chat/messages/{$a}", [], $this->headers())->assertOk()->assertJsonPath('data.is_deleted', true);

        $bobView = $this->messagesAs($this->bob, $conversation['id'])->json('data.messages');
        $this->assertTrue($bobView[0]['is_deleted']);
        $this->assertNull($bobView[0]['body']);

        $this->as($this->bob);
        $this->postJson("/api/mobile/v1/chat/conversations/{$conversation['id']}/messages/delete-for-me", ['messages' => [$b]], $this->headers())->assertOk();
        $this->assertCount(1, $this->messagesAs($this->bob, $conversation['id'])->json('data.messages'));
        $this->assertCount(2, $this->messagesAs($this->alice, $conversation['id'])->json('data.messages'));
    }

    public function test_reply_react_and_star(): void
    {
        $conversation = $this->openDirect($this->alice, $this->bob);
        $original = $this->sendText($this->alice, $conversation['id'], 'Question?')->json('data.id');

        $reply = $this->sendText($this->bob, $conversation['id'], 'Answer', ['reply_to' => $original])->assertCreated();
        $this->assertSame($original, $reply->json('data.reply_to.id'));
        $this->assertSame('Question?', $reply->json('data.reply_to.body'));

        $this->as($this->alice);
        $this->putJson("/api/mobile/v1/chat/messages/{$original}/reaction", ['emoji' => '❤️'], $this->headers())
            ->assertOk()->assertJsonPath('data.reactions.mine', '❤️')->assertJsonPath('data.reactions.total', 1);

        $this->putJson("/api/mobile/v1/chat/messages/{$original}/star", ['starred' => true], $this->headers())->assertOk();
        $this->assertCount(1, $this->getJson('/api/mobile/v1/chat/messages/starred', $this->headers())->json('data'));
    }

    public function test_media_messages_carry_their_files(): void
    {
        $conversation = $this->openDirect($this->alice, $this->bob);
        $this->as($this->alice);

        $response = $this->post("/api/mobile/v1/chat/conversations/{$conversation['id']}/messages", [
            'type' => 'image',
            'body' => 'Look',
            'files' => [UploadedFile::fake()->image('photo.jpg', 400, 300)],
        ], $this->headers() + ['Accept' => 'application/json'])->assertCreated();

        $this->assertCount(1, $response->json('data.attachments'));
        $this->assertSame('image/jpeg', $response->json('data.attachments.0.mime_type'));

        $gallery = $this->getJson("/api/mobile/v1/chat/conversations/{$conversation['id']}/gallery?kind=media", $this->headers())->assertOk();
        $this->assertCount(1, $gallery->json('data.messages'));
    }

    public function test_a_video_carries_its_poster_frame(): void
    {
        $conversation = $this->openDirect($this->alice, $this->bob);
        $this->as($this->alice);

        $response = $this->post("/api/mobile/v1/chat/conversations/{$conversation['id']}/messages", [
            'type' => 'video',
            'duration_ms' => 12000,
            'files' => [UploadedFile::fake()->create('clip.mp4', 800, 'video/mp4')],
            'thumbnail' => UploadedFile::fake()->image('poster.jpg', 320, 180),
        ], $this->headers() + ['Accept' => 'application/json'])->assertCreated();

        $this->assertNotNull($response->json('data.attachments.0.thumbnail'));
        $this->assertSame(12000, $response->json('data.meta.duration_ms'));

        // Replying to it shows the poster in the quote.
        $reply = $this->sendText($this->bob, $conversation['id'], 'Nice', ['reply_to' => $response->json('data.id')])->assertCreated();
        $this->assertNotNull($reply->json('data.reply_to.thumbnail'));
    }

    public function test_disappearing_messages_vanish_and_are_purged(): void
    {
        $this->saveContact($this->bob, $this->alice);
        $conversation = $this->openDirect($this->alice, $this->bob);

        $this->as($this->alice);
        $this->putJson("/api/mobile/v1/chat/conversations/{$conversation['id']}/disappearing", ['seconds' => 86400], $this->headers())
            ->assertOk()->assertJsonPath('data.disappearing_seconds', 86400);
        $this->sendText($this->alice, $conversation['id'], 'Gone tomorrow')->assertCreated();

        $this->travel(25)->hours();
        $bodies = collect($this->messagesAs($this->bob, $conversation['id'])->json('data.messages'))->pluck('body')->filter();
        $this->assertNotContains('Gone tomorrow', $bodies);

        $this->artisan('chat:purge')->assertSuccessful();
        $this->assertSame(0, ChatMessage::query()->where('body', 'Gone tomorrow')->count());
    }

    // ================================================================ groups

    public function test_groups_respect_roles_privacy_and_history(): void
    {
        $this->as($this->carol);
        $this->patchJson('/api/mobile/v1/chat/privacy', ['who_can_add_to_groups' => 'nobody'], $this->headers())->assertOk();

        $this->as($this->alice);
        $created = $this->postJson('/api/mobile/v1/chat/groups', ['name' => 'Trip', 'members' => [$this->bob->id, $this->carol->id]], $this->headers())->assertCreated();
        $group = $created->json('data.conversation.id');
        $this->assertSame(['user:'.$this->carol->id], $created->json('data.not_added'));
        $this->assertSame('owner', $created->json('data.conversation.my_role'));

        $this->sendText($this->alice, $group, 'Before Carol')->assertCreated();

        // A member can't remove people; only admins can switch the group to announcements.
        $this->as($this->bob);
        $members = $this->getJson("/api/mobile/v1/chat/groups/{$group}/members", $this->headers())->json('data');
        $aliceRow = collect($members)->firstWhere('role', 'owner')['participant_id'];
        $this->deleteJson("/api/mobile/v1/chat/groups/{$group}/members/{$aliceRow}", [], $this->headers())->assertStatus(403);
        $this->patchJson("/api/mobile/v1/chat/groups/{$group}/settings", ['only_admins_send' => true], $this->headers())->assertStatus(403);

        $this->as($this->alice);
        $this->patchJson("/api/mobile/v1/chat/groups/{$group}/settings", ['only_admins_send' => true], $this->headers())->assertOk();
        $this->sendText($this->bob, $group, 'Can I?')->assertStatus(403)->assertJsonPath('error_code', 'chat_admins_only');

        // Carol joins by link and doesn't see what came before.
        $this->as($this->alice);
        $token = $this->getJson("/api/mobile/v1/chat/groups/{$group}/invite", $this->headers())->json('data.token');
        $this->as($this->carol);
        $this->postJson("/api/mobile/v1/chat/invites/{$token}/join", [], $this->headers())->assertOk();
        $bodies = collect($this->messagesAs($this->carol, $group)->json('data.messages'))->pluck('body')->filter()->all();
        $this->assertNotContains('Before Carol', $bodies);

        // The owner leaves → the group gets a new owner.
        $this->as($this->alice);
        $this->postJson("/api/mobile/v1/chat/groups/{$group}/leave", [], $this->headers())->assertOk();
        $this->as($this->bob);
        $roles = collect($this->getJson("/api/mobile/v1/chat/groups/{$group}/members", $this->headers())->json('data'))->pluck('role');
        $this->assertContains('owner', $roles);
    }

    public function test_mentions_flag_the_mentioned_member(): void
    {
        $this->as($this->alice);
        $group = $this->postJson('/api/mobile/v1/chat/groups', ['name' => 'Team', 'members' => [$this->bob->id, $this->carol->id]], $this->headers())->json('data.conversation.id');
        $bobRow = collect($this->getJson("/api/mobile/v1/chat/groups/{$group}/members", $this->headers())->json('data'))
            ->firstWhere('profile.id', $this->bob->id)['participant_id'];

        $this->sendText($this->alice, $group, '@Bob look', ['mentions' => [$bobRow]])->assertCreated();

        $this->as($this->bob);
        $this->assertTrue($this->getJson('/api/mobile/v1/chat/conversations', $this->headers())->json('data.0.has_unread_mention'));
        $this->as($this->carol);
        $this->assertFalse($this->getJson('/api/mobile/v1/chat/conversations', $this->headers())->json('data.0.has_unread_mention'));
    }

    public function test_the_admin_group_limit_applies(): void
    {
        $admin = Admin::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'secret123', 'status' => 'active']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['chat-settings.view', 'chat-settings.update'] as $name) {
            Permission::findOrCreate($name, 'admin_api');
        }
        $admin->givePermissionTo(['chat-settings.view', 'chat-settings.update']);
        Sanctum::actingAs($admin, [], 'admin_api');

        $this->putJson('/api/admin/v1/chat-settings', ['max_group_members' => 2])->assertOk()->assertJsonPath('data.max_group_members', 2);
        $this->assertSame(2, ChatSetting::current()->max_group_members);

        $this->as($this->alice);
        $this->postJson('/api/mobile/v1/chat/groups', ['name' => 'Too big', 'members' => [$this->bob->id, $this->carol->id]], $this->headers())
            ->assertStatus(422)->assertJsonPath('error_code', 'chat_group_full');
    }

    // ================================================================ contacts

    public function test_sync_normalises_numbers_and_returns_who_is_registered(): void
    {
        $this->as($this->alice);
        $response = $this->postJson('/api/mobile/v1/chat/contacts/sync', ['full' => true, 'contacts' => [
            ['name' => 'Bobby', 'phone' => '050 000 0002'],
            ['name' => 'Carol C', 'phone' => '+966 50 000 0003'],
            ['name' => 'Nobody', 'phone' => '0599999999'],
        ]], $this->headers())->assertOk();

        $this->assertEqualsCanonicalizing(['Bobby', 'Carol C'], collect($response->json('data'))->pluck('name')->all());
        $this->assertSame(3, ChatContact::query()->where('owner_id', $this->alice->id)->count());
        $this->assertSame('+966500000002', ChatContact::query()->where('name', 'Bobby')->value('phone'));
    }

    public function test_sync_reads_numbers_in_the_country_of_my_own_phone(): void
    {
        // Saudi is the default country, but this user's own number is Egyptian and their
        // profile has no country yet: "010…" must still become +2010…, not +96610….
        $egp = Currency::create(['code' => 'EGP', 'symbol' => 'EGP', 'decimal_places' => 2]);
        $flag = Flag::create(['code' => 'eg']);
        Country::create(['code' => 'EG', 'dial_code' => '+20', 'phone_length' => 10, 'is_default' => false, 'flag_id' => $flag->id, 'currency_id' => $egp->id, 'status' => true]);
        $omar = User::create(['name' => 'Omar', 'phone' => '+201000000001', 'status' => 'active', 'phone_verified_at' => now()]);
        $mona = User::create(['name' => 'Mona', 'phone' => '+201000000002', 'status' => 'active', 'phone_verified_at' => now()]);

        $this->as($omar);
        $this->postJson('/api/mobile/v1/chat/contacts/sync', ['contacts' => [['name' => 'Mona', 'phone' => '0100 000 0002']]])
            ->assertOk()->assertJsonPath('data.0.profile.id', $mona->id);
    }

    public function test_lookup_by_number_and_by_qr(): void
    {
        $this->saudi->update(['phone_starts_with' => '5']);
        $this->as($this->alice);
        $this->postJson('/api/mobile/v1/chat/contacts/lookup', ['phone' => '0500000002'], $this->headers())->assertOk()->assertJsonPath('data.id', $this->bob->id);
        $this->postJson('/api/mobile/v1/chat/contacts/lookup', ['phone' => '0599999999'], $this->headers())->assertNotFound();

        // With or without the country code, spaces or the trunk 0 — the same person.
        foreach (['500000002', '966500000002', '+966 50 000 0002', '00966500000002'] as $written) {
            $this->postJson('/api/mobile/v1/chat/contacts/lookup', ['phone' => $written], $this->headers())->assertOk()->assertJsonPath('data.id', $this->bob->id);
        }
        $this->postJson('/api/mobile/v1/chat/contacts/lookup', ['phone' => '500000002', 'country_code' => 'SA'], $this->headers())->assertOk();

        // Only a whole number: too short, too long, or the wrong first digit is refused, not searched.
        foreach (['5000000', '5000000021', '400000002'] as $partial) {
            $this->postJson('/api/mobile/v1/chat/contacts/lookup', ['phone' => $partial], $this->headers())
                ->assertStatus(422)->assertJsonPath('error_code', 'chat_invalid_phone');
        }

        $this->as($this->bob);
        $payload = $this->getJson('/api/mobile/v1/chat/contacts/qr', $this->headers())->json('data.payload');
        $this->assertStringStartsWith('dorr://chat/', $payload);

        $this->as($this->alice);
        $this->postJson('/api/mobile/v1/chat/contacts/qr/resolve', ['payload' => $payload], $this->headers())->assertOk()->assertJsonPath('data.id', $this->bob->id);

        // Bob resets his code: the old one stops working.
        $this->as($this->bob);
        $this->postJson('/api/mobile/v1/chat/contacts/qr/reset', [], $this->headers())->assertOk();
        $this->as($this->alice);
        $this->postJson('/api/mobile/v1/chat/contacts/qr/resolve', ['payload' => $payload], $this->headers())->assertStatus(422);
    }

    // ================================================================ wallet cards

    public function test_a_transfer_receipt_is_built_from_my_own_transfer_only(): void
    {
        $this->seed(FinancialCategorySeeder::class);
        $wallets = app(WalletService::class);
        $aliceWallet = $wallets->firstOrCreateWallet($this->alice, $this->saudi);
        $bobWallet = $wallets->firstOrCreateWallet($this->bob, $this->saudi);
        $wallets->credit($aliceWallet, 10000, WalletBucket::Withdrawable, WalletTransactionType::Topup);
        $out = $wallets->transfer($aliceWallet, $bobWallet, 2500, WalletBucket::Withdrawable)['out'];

        $conversation = $this->openDirect($this->alice, $this->bob);

        $this->as($this->alice);
        $card = $this->postJson("/api/mobile/v1/chat/conversations/{$conversation['id']}/messages", [
            'type' => 'wallet_transfer', 'wallet_transaction_id' => $out->uuid,
        ], $this->headers())->assertCreated();
        $this->assertSame(2500, $card->json('data.meta.amount_minor'));
        $this->assertSame('SAR', $card->json('data.meta.currency'));
        $this->assertSame('B***', $card->json('data.meta.recipient_name'));

        // Bob can't pass Alice's transfer off as his.
        $this->as($this->bob);
        $this->postJson("/api/mobile/v1/chat/conversations/{$conversation['id']}/messages", [
            'type' => 'wallet_transfer', 'wallet_transaction_id' => $out->uuid,
        ], $this->headers())->assertStatus(422)->assertJsonPath('error_code', 'chat_wallet_transfer_not_found');

        // And a receipt can't be forwarded.
        $this->as($this->alice);
        $this->postJson('/api/mobile/v1/chat/messages/forward', ['messages' => [$card->json('data.id')], 'conversations' => [$conversation['id']]], $this->headers())
            ->assertStatus(422);
    }

    public function test_a_wallet_qr_card_comes_from_my_wallet_in_this_country(): void
    {
        $conversation = $this->openDirect($this->alice, $this->bob);

        $this->as($this->alice);
        $this->postJson("/api/mobile/v1/chat/conversations/{$conversation['id']}/messages", ['type' => 'wallet_qr'], $this->headers())
            ->assertStatus(422)->assertJsonPath('error_code', 'chat_wallet_not_found');

        $wallet = app(WalletService::class)->firstOrCreateWallet($this->alice, $this->saudi);

        $card = $this->postJson("/api/mobile/v1/chat/conversations/{$conversation['id']}/messages", ['type' => 'wallet_qr'], $this->headers())->assertCreated();
        $this->assertSame('dorr://wallet/SA/'.$wallet->wallet_number, $card->json('data.meta.qr_payload'));
    }

    // ================================================================ calls

    public function test_calls_need_livekit_to_be_configured(): void
    {
        config(['chat.livekit.url' => null, 'chat.livekit.api_key' => null, 'chat.livekit.api_secret' => null]);
        $this->saveContact($this->bob, $this->alice);
        $conversation = $this->openDirect($this->alice, $this->bob);

        $this->as($this->alice);
        $this->postJson("/api/mobile/v1/chat/conversations/{$conversation['id']}/calls", ['type' => 'audio'], $this->headers())
            ->assertStatus(503)->assertJsonPath('error_code', 'chat_calls_not_configured');
        $this->assertSame(0, ChatCall::query()->count());
    }

    public function test_a_call_rings_is_answered_and_ends_with_a_line_in_the_chat(): void
    {
        config(['chat.livekit.url' => 'wss://dorr.livekit.cloud', 'chat.livekit.api_key' => 'APIkey', 'chat.livekit.api_secret' => 'secret-secret-secret-secret-secret']);
        $this->saveContact($this->bob, $this->alice);
        $conversation = $this->openDirect($this->alice, $this->bob);

        $this->as($this->alice);
        $started = $this->postJson("/api/mobile/v1/chat/conversations/{$conversation['id']}/calls", ['type' => 'video'], $this->headers())->assertCreated();
        $callId = $started->json('data.call.id');
        $this->assertSame('ringing', $started->json('data.call.status'));
        $this->assertSame('wss://dorr.livekit.cloud', $started->json('data.join.url'));

        // The token is a valid HS256 JWT for this room and this person.
        [$header, $claims, $signature] = explode('.', $started->json('data.join.token'));
        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', "{$header}.{$claims}", 'secret-secret-secret-secret-secret', true)), '+/', '-_'), '=');
        $this->assertSame($expected, $signature);
        $decoded = json_decode(base64_decode(strtr($claims, '-_', '+/')), true);
        $this->assertSame('user:'.$this->alice->id, $decoded['sub']);
        $this->assertSame($started->json('data.join.room'), $decoded['video']['room']);

        // A second call in the same direct chat is refused while this one rings.
        $this->postJson("/api/mobile/v1/chat/conversations/{$conversation['id']}/calls", ['type' => 'audio'], $this->headers())->assertStatus(409);

        $this->as($this->bob);
        $this->postJson("/api/mobile/v1/chat/calls/{$callId}/accept", [], $this->headers())->assertOk()->assertJsonPath('data.call.status', 'ongoing');

        $this->travel(3)->minutes();
        $this->postJson("/api/mobile/v1/chat/calls/{$callId}/leave", [], $this->headers())->assertOk()->assertJsonPath('data.call.status', 'ended');

        $last = collect($this->messagesAs($this->alice, $conversation['id'])->json('data.messages'))->last();
        $this->assertSame('call', $last['type']);
        $this->assertSame('ended', $last['meta']['status']);
        $this->assertGreaterThanOrEqual(180, $last['meta']['duration_seconds']);
    }

    public function test_the_call_push_is_urgent_and_short_lived(): void
    {
        config([
            'chat.livekit.url' => 'wss://x', 'chat.livekit.api_key' => 'k', 'chat.livekit.api_secret' => 's',
            'services.onesignal.app_id' => 'app', 'services.onesignal.rest_api_key' => 'rest',
        ]);
        Http::fake(['api.onesignal.com/*' => Http::response(['id' => 'n1'])]);
        NotificationDevice::query()->create(['owner_type' => User::class, 'owner_id' => $this->bob->id, 'player_id' => 'bob-phone', 'platform' => 'android']);
        $this->saveContact($this->bob, $this->alice);
        $conversation = $this->openDirect($this->alice, $this->bob);

        $this->as($this->alice);
        $this->postJson("/api/mobile/v1/chat/conversations/{$conversation['id']}/calls", ['type' => 'video'], $this->headers())->assertCreated();

        // The app turns this push into a full-screen ringing call: it must be urgent, expire with
        // the ring, and carry what the ringing page shows.
        Http::assertSent(fn ($request) => $request['include_player_ids'] === ['bob-phone']
            && $request['priority'] === 10
            && $request['ttl'] === 45
            && $request['data']['type'] === 'chat_call'
            && $request['data']['event'] === 'chat.call.ringing'
            && $request['data']['caller_name'] === 'Alice'
            && $request['data']['call_type'] === 'video');
    }

    public function test_an_unanswered_call_becomes_missed(): void
    {
        config(['chat.livekit.url' => 'wss://x', 'chat.livekit.api_key' => 'k', 'chat.livekit.api_secret' => 's']);
        $this->saveContact($this->bob, $this->alice);
        $conversation = $this->openDirect($this->alice, $this->bob);

        $this->as($this->alice);
        $callId = $this->postJson("/api/mobile/v1/chat/conversations/{$conversation['id']}/calls", ['type' => 'audio'], $this->headers())->json('data.call.id');

        $this->travel(1)->minutes();
        $this->artisan('chat:expire-calls')->assertSuccessful();

        $this->getJson("/api/mobile/v1/chat/calls/{$callId}", $this->headers())->assertOk()->assertJsonPath('data.status', 'missed');
    }

    // ================================================================ helpers

    private function makeUser(string $name, string $phone): User
    {
        return User::create(['name' => $name, 'phone' => $phone, 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
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

    /**
     * `$owner` saves `$contact` in their contacts.
     */
    private function saveContact(User $owner, User $contact): void
    {
        ChatContact::query()->create(['owner_type' => 'user', 'owner_id' => $owner->id, 'name' => $contact->name, 'phone' => $contact->phone, 'contact_type' => 'user', 'contact_id' => $contact->id, 'source' => 'manual']);
    }

    /**
     * @return array<string, mixed>
     */
    private function openDirect(User $me, User $other): array
    {
        $this->as($me);

        return $this->postJson('/api/mobile/v1/chat/conversations/direct', ['participant_id' => $other->id], $this->headers())->assertOk()->json('data');
    }

    private function sendText(User $sender, string $conversationId, string $body, array $extra = []): TestResponse
    {
        $this->as($sender);

        return $this->postJson("/api/mobile/v1/chat/conversations/{$conversationId}/messages", ['type' => 'text', 'body' => $body] + $extra, $this->headers());
    }

    private function messagesAs(User $viewer, string $conversationId): TestResponse
    {
        $this->as($viewer);

        return $this->getJson("/api/mobile/v1/chat/conversations/{$conversationId}/messages", $this->headers())->assertOk();
    }
}
