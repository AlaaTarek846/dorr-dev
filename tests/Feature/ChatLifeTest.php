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
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\Chat\Models\ChatCommitment;
use Modules\Chat\Models\ChatContact;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Models\ChatParticipant;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * Daily intelligence, batch A (spec 148, 149, 152, 154; AT-LIFE-01): a message becomes a
 * commitment, a handoff or an agreement — nothing happens until the right person confirms, the
 * card in the chat follows every answer, and each one keeps its history.
 */
class ChatLifeTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    private User $carol;

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
        $this->carol = $make('Carol', '+966500000003');
        foreach ([$this->bob, $this->carol] as $other) {
            foreach ([[$this->alice, $other], [$other, $this->alice]] as [$owner, $contact]) {
                ChatContact::query()->create(['owner_type' => 'user', 'owner_id' => $owner->id, 'name' => $contact->name, 'phone' => $contact->phone, 'contact_type' => 'user', 'contact_id' => $contact->id, 'source' => 'manual']);
            }
        }

        config(['services.onesignal.app_id' => 'app', 'services.onesignal.rest_api_key' => 'rest']);
        Http::fake(['api.onesignal.com/*' => Http::response(['id' => 'n1'])]);
        foreach (['alice' => $this->alice, 'bob' => $this->bob] as $name => $u) {
            NotificationDevice::query()->create(['owner_type' => User::class, 'owner_id' => $u->id, 'player_id' => $name.'-phone', 'platform' => 'android']);
        }
        $this->travelTo(Carbon::parse('2026-10-20 09:00', 'UTC'));
    }

    public function test_a_message_becomes_a_commitment_the_one_responsible_accepts_and_is_reminded_of(): void
    {
        $chat = $this->direct($this->alice, $this->bob);
        $ask = $this->send($this->alice, $chat, ['type' => 'text', 'body' => 'Can you send the contract by Thursday?'])->json('data.id');

        // Alice asks Bob (in a direct chat, the other one by default): proposed, nothing else yet.
        $this->as($this->alice);
        $c = $this->postJson("/api/mobile/v1/chat/messages/{$ask}/commitment", ['due_at' => '2026-10-23T12:00:00Z', 'remind_minutes' => 60], $this->headers())
            ->assertCreated()->assertJsonPath('data.status', 'proposed')->assertJsonPath('data.assignee.name', 'Bob')
            ->assertJsonPath('data.title', 'Can you send the contract by Thursday?')->assertJsonPath('data.source.id', $ask);
        $id = $c->json('data.id');
        $card = ChatMessage::query()->where('type', 'commitment')->firstOrFail();
        $this->assertSame($id, $card->meta['id']);

        // Only Bob answers; Alice can't accept it for him.
        $this->postJson("/api/mobile/v1/chat/commitments/{$id}/respond", ['accept' => true], $this->headers())->assertForbidden()->assertJsonPath('error_code', 'chat_life_not_yours');
        $this->assertSame(0, $this->runReminders());

        $this->as($this->bob);
        $this->postJson("/api/mobile/v1/chat/commitments/{$id}/respond", ['accept' => true], $this->headers())->assertOk()->assertJsonPath('data.status', 'accepted');
        $this->assertSame('accepted', $card->refresh()->meta['status']);
        $this->getJson('/api/mobile/v1/chat/commitments?scope=mine', $this->headers())->assertOk()->assertJsonPath('data.0.id', $id);
        $this->as($this->alice);
        $this->getJson('/api/mobile/v1/chat/commitments?scope=theirs', $this->headers())->assertOk()->assertJsonPath('data.0.assignee.name', 'Bob');

        // An hour before: Bob is reminded, once.
        $this->travelTo(Carbon::parse('2026-10-23 10:30', 'UTC'));
        $this->assertSame(0, $this->runReminders());
        $this->travelTo(Carbon::parse('2026-10-23 11:01', 'UTC'));
        $this->assertSame(1, $this->runReminders());
        $this->assertSame(0, $this->runReminders());

        // Done, by the one responsible; then it's closed.
        $this->as($this->bob);
        $this->postJson("/api/mobile/v1/chat/commitments/{$id}/done", [], $this->headers())->assertOk()->assertJsonPath('data.status', 'done');
        $this->postJson("/api/mobile/v1/chat/commitments/{$id}/cancel", [], $this->headers())->assertStatus(409);
        $this->getJson("/api/mobile/v1/chat/commitments/{$id}", $this->headers())->assertOk()
            ->assertJsonPath('data.log.0.action', 'created')->assertJsonPath('data.log.1.action', 'accepted')->assertJsonPath('data.log.2.action', 'done');
    }

    public function test_taking_a_commitment_on_myself_is_accepting_it_and_in_a_group_i_pick_who(): void
    {
        $group = $this->group();
        $this->as($this->alice);
        $mine = $this->postJson("/api/mobile/v1/chat/conversations/{$group}/commitment", ['title' => 'Book the hall'], $this->headers())
            ->assertCreated()->assertJsonPath('data.status', 'accepted')->assertJsonPath('data.assignee.name', 'Alice');

        $carol = $this->pid($this->carol, $group);
        $this->postJson("/api/mobile/v1/chat/conversations/{$group}/commitment", ['title' => 'Bring the cake', 'assignee' => $carol], $this->headers())
            ->assertCreated()->assertJsonPath('data.status', 'proposed')->assertJsonPath('data.assignee.name', 'Carol');
        // Someone who isn't in the chat can't be given one.
        $other = $this->pid($this->bob, $this->direct($this->alice, $this->bob));
        $this->postJson("/api/mobile/v1/chat/conversations/{$group}/commitment", ['title' => 'x', 'assignee' => $other], $this->headers())->assertStatus(422);
        // Declining closes it.
        $id = ChatCommitment::query()->where('title', 'Bring the cake')->value('uuid');
        $this->as($this->carol);
        $this->postJson("/api/mobile/v1/chat/commitments/{$id}/respond", ['accept' => false], $this->headers())->assertOk()->assertJsonPath('data.status', 'declined');
        $this->postJson("/api/mobile/v1/chat/commitments/{$id}/respond", ['accept' => true], $this->headers())->assertStatus(409);
        $this->assertNotNull($mine->json('data.id'));
    }

    public function test_a_message_is_handed_to_a_member_or_forwarded_to_someone_who_takes_it_on(): void
    {
        $group = $this->group();
        $req = $this->send($this->bob, $group, ['type' => 'text', 'body' => 'The client wants a quote'])->json('data.id');

        // To a member of the group: the card is here.
        $this->as($this->bob);
        $h = $this->postJson("/api/mobile/v1/chat/messages/{$req}/handoff", ['to_participant_id' => $this->pid($this->carol, $group), 'note' => 'Please price it today'], $this->headers())
            ->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.to.name', 'Carol')->assertJsonPath('data.source.id', $req);
        $id = $h->json('data.id');
        $this->postJson("/api/mobile/v1/chat/handoffs/{$id}/accept", [], $this->headers())->assertForbidden();
        $this->as($this->carol);
        $this->postJson("/api/mobile/v1/chat/handoffs/{$id}/accept", [], $this->headers())->assertOk()->assertJsonPath('data.status', 'accepted');
        $this->getJson('/api/mobile/v1/chat/handoffs?scope=received', $this->headers())->assertOk()->assertJsonPath('data.0.id', $id);
        $this->postJson("/api/mobile/v1/chat/handoffs/{$id}/done", [], $this->headers())->assertOk()->assertJsonPath('data.status', 'done');

        // To someone outside: the message is forwarded to our chat, and the card answers it there.
        $this->as($this->alice);
        $out = $this->postJson("/api/mobile/v1/chat/messages/{$req}/handoff", ['to_user_id' => $this->carol->id], $this->headers())->assertCreated();
        $direct = ChatConversation::query()->where('direct_key', '!=', null)->whereHas('participants', fn ($q) => $q->where('participant_id', $this->carol->id))->firstOrFail();
        $copy = ChatMessage::query()->where('conversation_id', $direct->id)->where('is_forwarded', true)->firstOrFail();
        $card = ChatMessage::query()->where('conversation_id', $direct->id)->where('type', 'handoff')->firstOrFail();
        $this->assertSame('The client wants a quote', $copy->body);
        $this->assertSame($copy->id, $card->reply_to_id);
        $this->as($this->carol);
        $this->postJson("/api/mobile/v1/chat/handoffs/{$out->json('data.id')}/decline", [], $this->headers())->assertOk()->assertJsonPath('data.status', 'declined');
    }

    public function test_an_agreement_needs_every_party_and_a_change_starts_over_with_its_history(): void
    {
        $chat = $this->direct($this->alice, $this->bob);
        $offer = $this->send($this->bob, $chat, ['type' => 'text', 'body' => 'I can paint the flat next week for 2000'])->json('data.id');

        $this->as($this->alice);
        $terms = [['label' => 'Scope', 'value' => 'Paint the flat'], ['label' => 'Date', 'value' => '27 October'], ['label' => 'Proposed price', 'value' => '2000 SAR']];
        $a = $this->postJson("/api/mobile/v1/chat/conversations/{$chat}/agreements", ['title' => 'Painting', 'terms' => $terms, 'source' => $offer], $this->headers())
            ->assertCreated()->assertJsonPath('data.status', 'proposed')->assertJsonPath('data.version', 1)->assertJsonPath('data.record_only', true)
            ->assertJsonCount(2, 'data.parties');
        $id = $a->json('data.id');

        // Bob changes the price: version 2, Alice's approval starts over.
        $this->as($this->bob);
        $terms[2]['value'] = '2200 SAR';
        $this->putJson("/api/mobile/v1/chat/agreements/{$id}", ['terms' => $terms], $this->headers())->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.status', 'proposed');

        // An answer to the old version is refused.
        $this->as($this->alice);
        $this->postJson("/api/mobile/v1/chat/agreements/{$id}/answer", ['accept' => true, 'version' => 1], $this->headers())->assertStatus(409)->assertJsonPath('error_code', 'chat_agreement_changed');
        $this->postJson("/api/mobile/v1/chat/agreements/{$id}/answer", ['accept' => true, 'version' => 2], $this->headers())->assertOk()->assertJsonPath('data.status', 'agreed');
        // Everyone learns it in the chat.
        $this->assertTrue(ChatMessage::query()->where('type', 'system')->get()->contains(fn ($m) => ($m->meta['event'] ?? null) === 'agreement_agreed'));

        $this->getJson("/api/mobile/v1/chat/agreements/{$id}", $this->headers())->assertOk()
            ->assertJsonPath('data.terms.2.value', '2200 SAR')
            ->assertJsonPath('data.log.1.action', 'amended')->assertJsonPath('data.log.1.data.before.terms.2.value', '2000 SAR');
        // A third person can't open it.
        $this->as($this->carol);
        $this->getJson("/api/mobile/v1/chat/agreements/{$id}", $this->headers())->assertForbidden();
    }

    public function test_the_life_inbox_lists_what_needs_something_from_me_and_opens_where_it_is(): void
    {
        $group = $this->group();
        $chat = $this->direct($this->alice, $this->bob);

        // Alice asks Bob for something, mentions him in the group, and he marks a message "needs an answer".
        $ask = $this->send($this->alice, $chat, ['type' => 'text', 'body' => 'Send me the photos'])->json('data.id');
        $this->as($this->alice);
        $this->postJson("/api/mobile/v1/chat/messages/{$ask}/commitment", ['due_at' => '2026-10-21T10:00:00Z'], $this->headers())->assertCreated();
        $this->send($this->alice, $group, ['type' => 'text', 'body' => 'what do you think @Bob?', 'mentions' => [$this->pid($this->bob, $group)]])->assertCreated();
        $question = $this->send($this->alice, $chat, ['type' => 'text', 'body' => 'Dinner on Friday?'])->json('data.id');
        $this->as($this->bob);
        $this->putJson("/api/mobile/v1/chat/messages/{$question}/follow-up", ['on' => true], $this->headers())->assertOk();
        // And a meeting this afternoon in his calendar.
        $this->postJson('/api/mobile/v1/chat/calendar/items', ['title' => 'Dentist', 'starts_at' => '2026-10-20T14:00:00Z', 'timezone' => 'UTC'], $this->headers())->assertCreated();

        $inbox = $this->getJson('/api/mobile/v1/chat/life-inbox?timezone=UTC', $this->headers())->assertOk();
        $inbox->assertJsonPath('data.approve.0.kind', 'commitment')->assertJsonPath('data.approve.0.by', 'Alice')->assertJsonPath('data.approve.0.conversation_id', $chat)
            ->assertJsonPath('data.appointments.0.title', 'Dentist');
        $this->assertEqualsCanonicalizing(['needs_reply', 'mention'], collect($inbox->json('data.reply'))->pluck('kind')->all());

        // Bob takes it on: it moves to "follow up" for him, and Alice waits for it.
        $id = $inbox->json('data.approve.0.id');
        $this->postJson("/api/mobile/v1/chat/commitments/{$id}/respond", ['accept' => true], $this->headers())->assertOk();
        // Answering in the group answers the mention.
        $this->send($this->bob, $group, ['type' => 'text', 'body' => 'Sounds good'])->assertCreated();
        $this->as($this->bob);
        $after = $this->getJson('/api/mobile/v1/chat/life-inbox?timezone=UTC', $this->headers())->assertOk();
        $after->assertJsonPath('data.counts.approve', 0)->assertJsonPath('data.follow.0.kind', 'my_commitment');
        $this->assertSame(['needs_reply'], collect($after->json('data.reply'))->pluck('kind')->all());
        $this->as($this->alice);
        $this->getJson('/api/mobile/v1/chat/life-inbox?timezone=UTC', $this->headers())->assertOk()
            ->assertJsonPath('data.follow.0.kind', 'waiting_for')->assertJsonPath('data.follow.0.by', 'Bob');
    }

    // ------------------------------------------------------------------ helpers

    private function runReminders(): int
    {
        return app(\Modules\Chat\Services\LifeService::class)->remindDue();
    }

    private function pid(User $user, string $conversation): int
    {
        $id = ChatConversation::query()->where('uuid', $conversation)->value('id');

        return (int) ChatParticipant::query()->where('conversation_id', $id)->where('participant_id', $user->id)->value('id');
    }

    private function group(): string
    {
        $this->as($this->alice);

        return $this->postJson('/api/mobile/v1/chat/groups', ['name' => 'Team', 'members' => [$this->bob->id, $this->carol->id]], $this->headers())->json('data.conversation.id');
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

        return $this->postJson("/api/mobile/v1/chat/conversations/{$conversation}/messages", $data, $this->headers());
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
}
