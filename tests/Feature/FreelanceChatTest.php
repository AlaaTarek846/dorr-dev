<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Freelance\Events\FreelanceMessagesRead;
use Modules\Freelance\Events\FreelanceMessageSent;
use Modules\Freelance\Models\FreelanceConversation;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelanceMessage;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Chat\FreelanceChatService;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * FL-006: the client-freelancer chat — its own rooms and messages (not Modules/Chat), opened by the
 * server, readable only by the two people in it, live on a private channel.
 */
class FreelanceChatTest extends TestCase
{
    use RefreshDatabase;

    private Country $saudi;

    private User $client;

    private Freelancer $freelancer;

    private FreelanceConversation $room;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        $this->saudi = Country::create([
            'code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'status' => true,
            'flag_id' => Flag::create(['code' => 'sa'])->id,
            'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id,
        ]);
        foreach (['freelance', 'chat'] as $feature) {
            FreelanceFeatureRule::query()->create(['feature' => $feature, 'country_id' => $this->saudi->id, 'enabled' => true]);
        }
        FreelanceFeatures::forget();

        $this->client = $this->user('Mona Ali', '+966500000001');
        $this->freelancer = Freelancer::query()->create(['name' => 'Sara Hassan', 'phone' => '+966500000077', 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => 'verified']);
        $this->room = app(FreelanceChatService::class)->open($this->client, $this->freelancer, 'project', 42);
    }

    private function user(string $name, string $phone): User
    {
        return User::query()->create(['name' => $name, 'phone' => $phone, 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
    }

    private function asClient(?User $user = null): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user ?? $this->client, [], 'user_api');
    }

    private function asFreelancer(?Freelancer $freelancer = null): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($freelancer ?? $this->freelancer, [], 'freelance_api');
    }

    private function clientBase(): string
    {
        return '/api/mobile/v1/freelance/chat';
    }

    private function freelancerBase(): string
    {
        return '/api/freelance/v1/chat';
    }

    public function test_a_room_is_opened_once_per_context_by_the_server(): void
    {
        $again = app(FreelanceChatService::class)->open($this->client, $this->freelancer, 'project', 42);
        $other = app(FreelanceChatService::class)->open($this->client, $this->freelancer, 'project', 43);

        $this->assertSame($this->room->id, $again->id);
        $this->assertNotSame($this->room->id, $other->id);
        $this->assertSame(2, FreelanceConversation::query()->count());

        // No route lets an app open a room by itself.
        $this->asClient();
        $this->postJson($this->clientBase().'/conversations', ['freelancer_id' => $this->freelancer->id])->assertStatus(405);
    }

    public function test_both_sides_talk_and_see_each_other_with_unread_counts(): void
    {
        Event::fake([FreelanceMessageSent::class, FreelanceMessagesRead::class]);

        $this->asClient();
        $sent = $this->postJson($this->clientBase()."/conversations/{$this->room->uuid}/messages", ['body' => 'Hello, can you start Monday?', 'client_id' => 'c-1'])
            ->assertCreated()->assertJsonPath('data.from', 'client')->assertJsonPath('data.mine', true)->json('data');
        Event::assertDispatched(FreelanceMessageSent::class, fn ($e) => $e->uuid === $this->room->uuid && $e->message['body'] === 'Hello, can you start Monday?');

        $this->asFreelancer();
        $list = $this->getJson($this->freelancerBase().'/conversations')->assertOk()->assertJsonPath('data.0.unread', 1)->assertJsonPath('data.0.peer.name', 'Mona Ali')->json('data');
        $this->assertSame('Hello, can you start Monday?', $list[0]['last_message_preview']);
        $messages = $this->getJson($this->freelancerBase()."/conversations/{$this->room->uuid}/messages")->assertOk()->json('data');
        $this->assertSame('client', $messages[0]['from']);
        $this->assertFalse($messages[0]['mine']);

        $this->postJson($this->freelancerBase()."/conversations/{$this->room->uuid}/read")->assertOk()->assertJsonPath('data.read', 1);
        Event::assertDispatched(FreelanceMessagesRead::class, fn ($e) => $e->reader === 'freelancer' && $e->upToId === $sent['id']);
        $this->getJson($this->freelancerBase().'/conversations')->assertJsonPath('data.0.unread', 0);

        $this->postJson($this->freelancerBase()."/conversations/{$this->room->uuid}/messages", ['body' => 'Yes, Monday works.'])->assertCreated()->assertJsonPath('data.from', 'freelancer');

        $this->asClient();
        $this->getJson($this->clientBase().'/conversations')->assertOk()->assertJsonPath('data.0.unread', 1)->assertJsonPath('data.0.peer.name', 'Sara Hassan');
        $this->getJson($this->clientBase()."/conversations/{$this->room->uuid}/messages")->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.body', 'Yes, Monday works.');
        // The client's own message now shows as read.
        $this->assertNotNull(FreelanceMessage::query()->where('sender_type', 'client')->value('read_at'));
    }

    public function test_the_same_client_id_is_one_message_and_an_empty_message_is_refused(): void
    {
        $this->asClient();
        $url = $this->clientBase()."/conversations/{$this->room->uuid}/messages";

        $first = $this->postJson($url, ['body' => 'Hi', 'client_id' => 'dup'])->assertCreated()->json('data.id');
        $second = $this->postJson($url, ['body' => 'Hi', 'client_id' => 'dup'])->assertCreated()->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, FreelanceMessage::query()->count());
        $this->postJson($url, ['body' => '   '])->assertUnprocessable()->assertJsonPath('error_code', 'freelance_chat_empty');
    }

    public function test_a_stranger_cannot_read_or_write_in_a_room(): void
    {
        $this->asClient();
        $this->postJson($this->clientBase()."/conversations/{$this->room->uuid}/messages", ['body' => 'secret'])->assertCreated();
        $messageId = FreelanceMessage::query()->value('id');

        $otherClient = $this->user('Nora', '+966500000002');
        $otherFreelancer = Freelancer::query()->create(['name' => 'Lina', 'phone' => '+966500000078', 'country_id' => $this->saudi->id, 'phone_verified_at' => now()]);

        foreach ([['client', $otherClient], ['freelancer', $otherFreelancer]] as [$kind, $person]) {
            $kind === 'client' ? $this->asClient($person) : $this->asFreelancer($person);
            $base = $kind === 'client' ? $this->clientBase() : $this->freelancerBase();
            $this->getJson("{$base}/conversations")->assertOk()->assertJsonCount(0, 'data');
            $this->getJson("{$base}/conversations/{$this->room->uuid}")->assertNotFound();
            $this->getJson("{$base}/conversations/{$this->room->uuid}/messages")->assertNotFound();
            $this->postJson("{$base}/conversations/{$this->room->uuid}/messages", ['body' => 'hi'])->assertNotFound();
            $this->postJson("{$base}/conversations/{$this->room->uuid}/read")->assertNotFound();
            $this->deleteJson("{$base}/messages/{$messageId}")->assertNotFound();
        }

        // The other person in the room can't take it back either.
        $this->asFreelancer();
        $this->deleteJson($this->freelancerBase()."/messages/{$messageId}")->assertNotFound();
    }

    public function test_a_file_and_an_image_are_sent_as_attachments(): void
    {
        Storage::fake('public');
        $this->asFreelancer();
        $url = $this->freelancerBase()."/conversations/{$this->room->uuid}/messages";

        $this->postJson($url, ['file' => UploadedFile::fake()->create('brief.pdf', 200, 'application/pdf'), 'body' => 'The brief'])
            ->assertCreated()->assertJsonPath('data.kind', 'file')->assertJsonPath('data.body', 'The brief')->assertJsonStructure(['data' => ['attachment' => ['url', 'name', 'mime', 'size']]]);
        $this->postJson($url, ['file' => UploadedFile::fake()->image('logo.png', 40, 40)])
            ->assertCreated()->assertJsonPath('data.kind', 'image');
        $this->postJson($url, ['file' => UploadedFile::fake()->create('virus.exe', 10)])->assertUnprocessable();
        $this->postJson($url, ['file' => UploadedFile::fake()->create('huge.pdf', (int) config('freelance.upload_max_kb') + 1, 'application/pdf')])->assertUnprocessable();
    }

    public function test_the_sender_takes_a_message_back_within_five_minutes_only(): void
    {
        $this->asClient();
        $id = $this->postJson($this->clientBase()."/conversations/{$this->room->uuid}/messages", ['body' => 'oops'])->json('data.id');

        $this->deleteJson($this->clientBase()."/messages/{$id}")->assertOk();
        $this->getJson($this->clientBase()."/conversations/{$this->room->uuid}/messages")->assertJsonPath('data.0.kind', 'deleted')->assertJsonPath('data.0.body', null);
        $this->deleteJson($this->clientBase()."/messages/{$id}")->assertUnprocessable()->assertJsonPath('error_code', 'freelance_message_not_deletable');

        $old = FreelanceMessage::query()->create(['conversation_id' => $this->room->id, 'sender_type' => 'client', 'sender_id' => $this->client->id, 'kind' => 'text', 'body' => 'old', 'created_at' => now()->subMinutes(10)]);
        $this->deleteJson($this->clientBase()."/messages/{$old->id}")->assertUnprocessable()->assertJsonPath('error_code', 'freelance_message_not_deletable');
    }

    public function test_a_read_only_room_or_a_switched_off_chat_keeps_the_history_but_takes_no_messages(): void
    {
        $this->asClient();
        $url = $this->clientBase()."/conversations/{$this->room->uuid}/messages";
        $this->postJson($url, ['body' => 'before'])->assertCreated();

        app(FreelanceChatService::class)->setStatus($this->room, FreelanceConversation::READ_ONLY);
        $this->postJson($url, ['body' => 'after'])->assertStatus(409)->assertJsonPath('error_code', 'freelance_chat_closed');
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($this->clientBase()."/conversations/{$this->room->uuid}")->assertJsonPath('data.can_write', false);

        app(FreelanceChatService::class)->setStatus($this->room, FreelanceConversation::OPEN);
        $this->postJson($url, ['body' => 'open again'])->assertCreated();

        FreelanceFeatureRule::query()->where('feature', 'chat')->update(['enabled' => false]);
        FreelanceFeatures::forget();
        $this->postJson($url, ['body' => 'switch off'])->assertStatus(409);
        $this->getJson($url)->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_the_messenger_chat_is_not_involved_and_tokens_do_not_cross(): void
    {
        // The freelancer is not a participant of Modules/Chat and has no route into it.
        $this->asFreelancer();
        $this->getJson('/api/freelance/v1/chat/realtime-config')->assertNotFound();
        $this->getJson('/api/freelance/v1/chat/groups')->assertNotFound();
        $this->getJson('/api/mobile/v1/chat/conversations')->assertUnauthorized();
        $this->assertNotContains('freelancer', array_keys(config('chat.participants')));
        $this->assertNotContains('freelancer', config('chat.enabled_participants'));

        // A client's token is no key to the freelancer's chat, nor the other way round.
        $this->asClient();
        $this->getJson($this->freelancerBase().'/conversations')->assertUnauthorized();
        $this->asFreelancer();
        $this->getJson($this->clientBase().'/conversations')->assertUnauthorized();
    }
}
