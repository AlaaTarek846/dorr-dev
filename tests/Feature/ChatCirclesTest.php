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
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * Privacy circles (spec 98–103, acceptance AT-PRIV-01/02/03/07): a circle's own notification level
 * (P2 shows only the circle's — stand-in — name, P4 nothing), its chats out of the main list and
 * search, inside the circle only.
 */
class ChatCirclesTest extends TestCase
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
        foreach ([[$this->alice, $this->bob], [$this->bob, $this->alice], [$this->alice, $this->carol], [$this->carol, $this->alice]] as [$owner, $contact]) {
            ChatContact::query()->create(['owner_type' => 'user', 'owner_id' => $owner->id, 'name' => $contact->name, 'phone' => $contact->phone, 'contact_type' => 'user', 'contact_id' => $contact->id, 'source' => 'manual']);
        }

        config(['services.onesignal.app_id' => 'app', 'services.onesignal.rest_api_key' => 'rest']);
        Http::fake(['api.onesignal.com/*' => Http::response(['id' => 'n1'])]);
        NotificationDevice::query()->create(['owner_type' => User::class, 'owner_id' => $this->alice->id, 'player_id' => 'alice-phone', 'platform' => 'android']);
    }

    public function test_a_p2_circle_shows_only_its_stand_in_name(): void
    {
        $bobChat = $this->direct($this->bob, $this->alice);

        $this->as($this->alice);
        $circle = $this->postJson('/api/mobile/v1/chat/circles', ['name' => 'Secret friends', 'masked_name' => 'Club', 'emoji' => '⭐', 'disclosure' => 'circle'], $this->headers())
            ->assertCreated()->assertJsonPath('data.shown_name', 'Club')->json('data.id');
        $this->putJson("/api/mobile/v1/chat/conversations/{$bobChat}/circle", ['circle_id' => $circle], $this->headers())
            ->assertOk()->assertJsonPath('data.circle.shown_name', 'Club');

        $this->send($this->bob, $bobChat, 'where are you?');

        Http::assertSent(fn ($r) => ($r['headings']['en'] ?? null) === 'Club' && $r['contents']['en'] === 'New message' && $r['data']['circle'] === $circle);
        Http::assertNotSent(fn ($r) => str_contains(json_encode($r->data()), 'Bob') || str_contains(json_encode($r->data()), 'where are you'));
    }

    public function test_p4_sends_no_notification_and_the_stricter_level_wins(): void
    {
        $bobChat = $this->direct($this->bob, $this->alice);
        $this->as($this->alice);
        $circle = $this->postJson('/api/mobile/v1/chat/circles', ['name' => 'Quiet', 'disclosure' => 'hidden'], $this->headers())->json('data.id');
        $this->putJson("/api/mobile/v1/chat/conversations/{$bobChat}/circle", ['circle_id' => $circle], $this->headers())->assertOk();

        $this->send($this->bob, $bobChat, 'hi');
        Http::assertSent(fn ($r) => ($r['data']['hidden'] ?? null) === '1' && ($r['headings']['en'] ?? null) === 'Dorr');

        // A circle that would show everything can't undo my "show nothing" setting.
        $this->as($this->alice);
        $this->patchJson("/api/mobile/v1/chat/circles/{$circle}", ['disclosure' => 'all'], $this->headers())->assertOk();
        $this->patchJson('/api/mobile/v1/chat/privacy', ['notification_privacy' => 'none'], $this->headers())->assertOk();
        $this->send($this->bob, $bobChat, 'are you there');
        Http::assertNotSent(fn ($r) => ($r['contents']['en'] ?? null) === 'are you there');
    }

    public function test_its_chats_leave_the_main_list_and_search_and_show_inside_the_circle(): void
    {
        $bobChat = $this->direct($this->bob, $this->alice);
        $carolChat = $this->direct($this->carol, $this->alice);
        $this->send($this->bob, $bobChat, 'secret plan');
        $this->send($this->carol, $carolChat, 'lunch?');

        $this->as($this->alice);
        $circle = $this->postJson('/api/mobile/v1/chat/circles', ['name' => 'Family', 'locked' => true], $this->headers())->json('data.id');
        $this->putJson("/api/mobile/v1/chat/conversations/{$bobChat}/circle", ['circle_id' => $circle], $this->headers())->assertOk();

        $ids = fn (array $q = []) => collect($this->getJson('/api/mobile/v1/chat/conversations?'.http_build_query($q), $this->headers())->json('data'))->pluck('id')->all();
        $this->assertSame([$carolChat], $ids());
        $this->assertSame([], $ids(['search' => 'secret']));
        $this->assertSame([$bobChat], $ids(['circle' => $circle]));

        $this->getJson('/api/mobile/v1/chat/circles', $this->headers())->assertOk()
            ->assertJsonPath('data.0.chats_count', 1)->assertJsonPath('data.0.unread', 1)->assertJsonPath('data.0.locked', true);

        // Not hidden any more: back in the list. Out of the circle: same. Deleted circle: same.
        $this->patchJson("/api/mobile/v1/chat/circles/{$circle}", ['hide_from_list' => false], $this->headers())->assertOk();
        $this->assertCount(2, $ids());
        $this->deleteJson("/api/mobile/v1/chat/circles/{$circle}", [], $this->headers())->assertOk();
        $this->getJson("/api/mobile/v1/chat/conversations/{$bobChat}", $this->headers())->assertJsonPath('data.circle', null);

        // Someone else's circle is nobody else's business.
        $this->as($this->bob);
        $this->putJson("/api/mobile/v1/chat/conversations/{$bobChat}/circle", ['circle_id' => $circle], $this->headers())->assertNotFound();
    }

    // ------------------------------------------------------------------ helpers

    private function direct(User $me, User $other): string
    {
        $this->as($me);

        return $this->postJson('/api/mobile/v1/chat/conversations/direct', ['participant_id' => $other->id], $this->headers())->assertOk()->json('data.id');
    }

    private function send(User $sender, string $conversation, string $body): void
    {
        $this->as($sender);
        $this->postJson("/api/mobile/v1/chat/conversations/{$conversation}/messages", ['type' => 'text', 'body' => $body], $this->headers())->assertCreated();
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
