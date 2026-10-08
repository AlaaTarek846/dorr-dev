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
use Modules\AI\Models\AiProvider;
use Modules\AI\Repositories\AiProviderRepository;
use Modules\Chat\Models\ChatContact;
use Modules\Chat\Models\ChatGroup;
use Modules\Chat\Models\ChatMessage;
use Modules\Discover\Database\Seeders\DiscoverSeeder;
use Modules\Discover\Models\DiscoverCategory;
use Modules\Discover\Models\DiscoverCity;
use Modules\Discover\Models\DiscoverEvent;
use Modules\Discover\Models\DiscoverOrganizer;
use Modules\Discover\Models\DiscoverSetting;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * DORR Discover (spec 169–182; AT-DISC-01, AT-DISC-02, AT-DISC-03): trusted events by city and
 * date in their own time zone, organizers reviewed before they're trusted, status changes told
 * only to the interested who asked, travel search by the destination's days, and the chat
 * (cards, polls, rooms), the calendar and "don't miss it" alerts around them.
 */
class DiscoverTest extends TestCase
{
    use RefreshDatabase;

    private Country $saudi;

    private Country $egypt;

    private Country $emirates;

    private User $alice;

    private User $bob;

    private User $carol;

    private string $aiReply = '{}';

    protected function setUp(): void
    {
        parent::setUp();

        $flag = Flag::create(['code' => 'sa']);
        $currency = Currency::create(['code' => 'SAR', 'symbol' => 'SAR', 'decimal_places' => 2]);
        $country = fn (string $code, string $dial, bool $default = false) => Country::create(['code' => $code, 'dial_code' => $dial, 'phone_length' => 9, 'is_default' => $default, 'flag_id' => $flag->id, 'currency_id' => $currency->id, 'status' => true]);
        $this->saudi = $country('SA', '+966', true);
        $this->egypt = $country('EG', '+20');
        $this->emirates = $country('AE', '+971');
        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $code => $direction) {
            Language::create(['code' => $code, 'direction' => $direction, 'is_default_website' => $code === 'en', 'is_default_dashboard' => $code === 'en', 'stores_translation' => true, 'status' => true, 'flag_id' => $flag->id]);
        }
        $this->seed(DiscoverSeeder::class);

        $make = fn (string $name, string $phone) => User::create(['name' => $name, 'phone' => $phone, 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->alice = $make('Alice', '+966500000001');
        $this->bob = $make('Bob', '+966500000002');
        $this->carol = $make('Carol', '+966500000003');
        foreach ([[$this->alice, $this->bob], [$this->bob, $this->alice], [$this->alice, $this->carol], [$this->carol, $this->alice]] as [$owner, $contact]) {
            ChatContact::query()->create(['owner_type' => 'user', 'owner_id' => $owner->id, 'name' => $contact->name, 'phone' => $contact->phone, 'contact_type' => 'user', 'contact_id' => $contact->id, 'source' => 'manual']);
        }
        foreach (['alice' => $this->alice, 'bob' => $this->bob, 'carol' => $this->carol] as $name => $user) {
            NotificationDevice::query()->create(['owner_type' => User::class, 'owner_id' => $user->id, 'player_id' => $name.'-phone', 'platform' => 'android']);
        }

        app(AiProviderRepository::class)->ensureDefaults();
        AiProvider::query()->where('key', 'openai')->firstOrFail()->update(['is_enabled' => true, 'is_default' => true, 'api_key' => 'sk-test', 'model' => 'gpt-4o-mini']);
        config(['services.onesignal.app_id' => 'app', 'services.onesignal.rest_api_key' => 'rest']);
        Http::fake([
            'api.openai.com/v1/chat/completions' => fn () => Http::response(['choices' => [['message' => ['content' => $this->aiReply]]]]),
            'api.onesignal.com/*' => Http::response(['id' => 'n1']),
        ]);

        $this->travelTo(Carbon::parse('2026-11-01 07:00', 'UTC')); // a Sunday, 10:00 in Riyadh
    }

    // ------------------------------------------------------------------ AT-DISC-02

    public function test_an_organizers_event_waits_for_review_and_is_verified_only_when_the_organizer_is(): void
    {
        $this->as($this->bob);
        $this->postJson('/api/mobile/v1/discover/organizer/events', $this->eventData('Jazz night'), $this->headers())
            ->assertForbidden()->assertJsonPath('error_code', 'discover_not_organizer');

        $this->postJson('/api/mobile/v1/discover/organizer', ['name' => 'Bob Live'], $this->headers())->assertOk()->assertJsonPath('data.status', 'pending');
        $id = $this->postJson('/api/mobile/v1/discover/organizer/events', $this->eventData('Jazz night'), $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.review_status', 'pending')
            ->assertJsonPath('data.verified', false)
            // 20:00 in Riyadh is 17:00 UTC.
            ->assertJsonPath('data.starts_at', '2026-11-05T17:00:00+00:00')
            ->assertJsonPath('data.local_time', '20:00')
            ->json('data.id');

        // Not public while it waits.
        $this->as($this->alice);
        $this->assertSame([], $this->getJson('/api/mobile/v1/discover/events', $this->headers())->assertOk()->json('data'));
        $this->getJson("/api/mobile/v1/discover/events/{$id}", $this->headers())->assertNotFound();

        // The same event again is refused, pointing at the first.
        $this->as($this->bob);
        $this->postJson('/api/mobile/v1/discover/organizer/events', ['title' => '  JAZZ   night '] + $this->eventData('x'), $this->headers())
            ->assertStatus(409)->assertJsonPath('error_code', 'discover_duplicate');

        // The admin approves it: public now, but still not "verified".
        $this->asAdmin(['discover-events.update', 'discover-organizers.update']);
        $this->patchJson("/api/admin/v1/discover-events/{$id}/review", ['review_status' => 'approved'])->assertOk();
        $this->as($this->alice);
        $this->getJson('/api/mobile/v1/discover/events', $this->headers())->assertOk()
            ->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.verified', false)->assertJsonPath('data.0.organizer.verified', false);

        // Once the organizer is verified: shown as verified, and new events go live directly.
        $this->asAdmin(['discover-organizers.update'], 'b@example.com');
        $organizer = DiscoverOrganizer::query()->firstOrFail();
        $this->patchJson("/api/admin/v1/discover-organizers/{$organizer->id}/review", ['status' => 'verified'])->assertOk()->assertJsonPath('data.status', 'verified');
        $this->as($this->bob);
        $this->postJson('/api/mobile/v1/discover/organizer/events', $this->eventData('Oud evening', '2026-11-06 21:00'), $this->headers())
            ->assertCreated()->assertJsonPath('data.review_status', 'approved')->assertJsonPath('data.verified', true);
        $this->as($this->alice);
        $this->assertCount(2, $this->getJson('/api/mobile/v1/discover/events', $this->headers())->assertOk()->json('data'));
    }

    // ------------------------------------------------------------------ AT-DISC-01

    public function test_a_status_change_reaches_only_the_interested_who_asked_and_is_kept_in_the_history(): void
    {
        $event = $this->adminEvent('Riyadh Season opening', 'Riyadh', '2026-11-10 19:00');
        $this->as($this->alice);
        $this->postJson("/api/mobile/v1/discover/events/{$event->uuid}/interest", [], $this->headers())->assertOk()->assertJsonPath('data.interested', true)->assertJsonPath('data.notify', true);
        $this->as($this->bob);
        $this->postJson("/api/mobile/v1/discover/events/{$event->uuid}/interest", ['notify' => false], $this->headers())->assertOk();
        $this->assertSame(2, $event->refresh()->interested_count);

        $this->asAdmin(['discover-events.update']);
        $this->patchJson("/api/admin/v1/discover-events/{$event->uuid}/status", ['status' => 'postponed', 'note' => 'Weather', 'starts_at' => '2026-11-12 19:00'])
            ->assertOk()->assertJsonPath('data.status', 'postponed')->assertJsonPath('data.starts_at', '2026-11-12T16:00:00+00:00');

        $pushes = $this->pushes('discover.event.changed');
        $this->assertCount(1, $pushes);
        $this->assertSame(['alice-phone'], $pushes[0]['include_player_ids']);
        $this->assertSame('postponed', $pushes[0]['data']['status']);

        $this->as($this->carol);
        $this->getJson("/api/mobile/v1/discover/events/{$event->uuid}", $this->headers())->assertOk()
            ->assertJsonPath('data.status', 'postponed')
            ->assertJsonPath('data.status_note', 'Weather')
            ->assertJsonPath('data.old_starts_at', '2026-11-10T16:00:00+00:00')
            ->assertJsonPath('data.history.0.to', 'postponed');

        // Ending it tells nobody.
        $this->asAdmin(['discover-events.update'], 'b@example.com');
        $this->patchJson("/api/admin/v1/discover-events/{$event->uuid}/status", ['status' => 'ended'])->assertOk();
        $this->assertCount(1, $this->pushes('discover.event.changed'));
    }

    // ------------------------------------------------------------------ AT-DISC-03

    public function test_travel_search_shows_the_destinations_events_on_its_own_days_wherever_i_am(): void
    {
        $dubai = $this->city('Dubai');
        // 01:00 on the 21st in Dubai is still the 20th in Cairo — it belongs to the 21st there.
        $late = $this->adminEvent('Late show', 'Dubai', '2026-11-21 01:00');
        $this->adminEvent('Too late', 'Dubai', '2026-11-23 10:00');
        $this->adminEvent('Wrong city', 'Riyadh', '2026-11-21 20:00');

        $this->as($this->alice);
        $this->getJson("/api/mobile/v1/discover/travel?city_id={$dubai->id}&from=2026-11-21&to=2026-11-22&timezone=Africa/Cairo", ['X-Country' => 'EG'])
            ->assertOk()
            ->assertJsonPath('data.city.name', 'Dubai')
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $late->uuid)
            ->assertJsonPath('data.items.0.local_date', '2026-11-21')
            ->assertJsonPath('data.items.0.local_time', '01:00')
            ->assertJsonPath('data.items.0.my_time', '2026-11-20 23:00');

        $this->getJson("/api/mobile/v1/discover/travel?city_id={$dubai->id}&from=2026-11-01&to=2026-12-30", $this->headers())
            ->assertStatus(422)->assertJsonPath('error_code', 'discover_travel_range');
    }

    // ------------------------------------------------------------------ interests, calendar, follows, home

    public function test_interested_events_are_in_my_calendar_and_my_home_follows_my_interests_and_places(): void
    {
        $concert = $this->adminEvent('Big concert', 'Riyadh', '2026-11-03 21:00', 'concerts');
        $this->adminEvent('Expo', 'Jeddah', '2026-11-04 10:00', 'exhibitions', ['is_free' => true]);
        $this->adminEvent('Dubai fair', 'Dubai', '2026-11-05 18:00', 'exhibitions');

        $this->as($this->alice);
        $concerts = DiscoverCategory::query()->where('key', 'concerts')->value('id');
        $this->putJson('/api/mobile/v1/discover/preferences', ['categories' => [$concerts]], $this->headers())->assertOk()->assertJsonPath('data.categories', [$concerts]);
        $this->postJson('/api/mobile/v1/discover/follows', ['kind' => 'city', 'target_id' => $this->city('Dubai')->id], $this->headers())->assertOk()->assertJsonPath('data.0.name', 'Dubai');

        $home = $this->getJson('/api/mobile/v1/discover/home', $this->headers())->assertOk()->json('data');
        $sections = collect($home['sections'])->keyBy('key');
        $this->assertSame(['Big concert'], array_column($sections['for_you']['items'], 'title'));
        $this->assertSame(['Expo'], array_column($sections['free']['items'], 'title'));
        $this->assertSame(['Dubai fair'], array_column($sections['followed']['items'], 'title'));
        $this->assertNotContains('Dubai fair', array_column($sections['this_week']['items'], 'title'));

        $this->postJson("/api/mobile/v1/discover/events/{$concert->uuid}/interest", [], $this->headers())->assertOk();
        $this->getJson('/api/mobile/v1/chat/calendar?from=2026-11-01&to=2026-11-07&timezone=Asia/Riyadh', $this->headers())->assertOk()
            ->assertJsonPath('data.items.0.type', 'discover')
            ->assertJsonPath('data.items.0.ref', $concert->uuid)
            ->assertJsonPath('data.items.0.date', '2026-11-03');
        $this->assertSame(['Big concert'], array_column($this->getJson('/api/mobile/v1/discover/interests', $this->headers())->json('data'), 'title'));

        $this->deleteJson("/api/mobile/v1/discover/events/{$concert->uuid}/interest", [], $this->headers())->assertOk();
        $this->assertSame(0, $concert->refresh()->interested_count);
        $this->assertSame([], $this->getJson('/api/mobile/v1/chat/calendar?from=2026-11-01&to=2026-11-07', $this->headers())->json('data.items'));
    }

    // ------------------------------------------------------------------ chat: card, poll, room

    public function test_an_event_is_shared_as_a_card_with_a_poll_and_a_room_closes_after_it(): void
    {
        $event = $this->adminEvent('Food festival', 'Riyadh', '2026-11-07 17:00', 'food', ['ends_at' => '2026-11-07 23:00']);
        $this->as($this->alice);
        $chat = $this->postJson('/api/mobile/v1/chat/conversations/direct', ['participant_id' => $this->bob->id], $this->headers())->assertOk()->json('data.id');

        $shared = $this->postJson("/api/mobile/v1/discover/events/{$event->uuid}/share", ['conversation_id' => $chat, 'poll' => true], $this->headers())
            ->assertCreated()->json('data');
        $card = ChatMessage::query()->where('uuid', $shared['message_id'])->firstOrFail();
        $this->assertSame('event_card', $card->type->value);
        $this->assertSame($event->uuid, $card->meta['event']['id']);
        $this->assertSame('17:00', $card->meta['event']['local_time']);
        $poll = ChatMessage::query()->where('uuid', $shared['poll_id'])->firstOrFail();
        $this->assertSame(['I\'m going', 'Maybe', 'Can\'t make it'], array_column($poll->meta['options'], 'text'));

        $room = $this->postJson("/api/mobile/v1/discover/events/{$event->uuid}/room", ['members' => [$this->bob->id, $this->carol->id]], $this->headers())
            ->assertCreated()->json('data.conversation.id');
        $group = ChatGroup::query()->whereHas('conversation', fn ($q) => $q->where('uuid', $room))->firstOrFail();
        $this->assertStringContainsString('Food festival', $group->name);

        // Not yet: the event isn't over.
        $this->artisan('discover:close-rooms')->assertSuccessful();
        $this->assertFalse($group->refresh()->only_admins_send);
        // A day after it ended (the admin's default): only admins write.
        $this->travelTo(Carbon::parse('2026-11-08 21:00', 'UTC'));
        $this->artisan('discover:close-rooms')->assertSuccessful();
        $this->assertTrue($group->refresh()->only_admins_send);
        $this->assertDatabaseHas('discover_event_rooms', ['conversation_id' => $group->conversation_id]);
        $this->assertNotNull(\Modules\Discover\Models\DiscoverEventRoom::query()->first()->closed_at);
    }

    // ------------------------------------------------------------------ AI (178)

    public function test_ask_dorr_ai_only_builds_filters_and_never_invents_an_event(): void
    {
        $dubai = $this->city('Dubai');
        $kids = $this->adminEvent('Kids science day', 'Dubai', '2026-11-06 10:00', 'family', ['family_friendly' => true]);
        $this->adminEvent('Night club', 'Dubai', '2026-11-06 23:00', 'concerts');

        $this->aiReply = json_encode(['city_id' => $dubai->id, 'categories' => ['family', 'made_up'], 'from' => '2026-11-06', 'to' => '2026-11-07', 'family' => true, 'free' => false, 'q' => 'Imaginary Gala']);
        $this->as($this->alice);
        $this->postJson('/api/mobile/v1/discover/ask', ['text' => 'something for the kids in Dubai this weekend'], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.filters.city', 'Dubai')
            ->assertJsonPath('data.filters.family', true)
            ->assertJsonMissingPath('data.filters.q') // matched nothing: dropped, the rest still searched
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $kids->uuid);

        DiscoverSetting::query()->firstOrFail()->update(['ai_enabled' => false]);
        $this->postJson('/api/mobile/v1/discover/ask', ['text' => 'anything'], $this->headers())->assertForbidden()->assertJsonPath('error_code', 'discover_ai_off');
    }

    // ------------------------------------------------------------------ alerts (172), switches (182)

    public function test_dont_miss_it_alerts_match_my_interests_once_and_respect_the_weekly_limit(): void
    {
        $concerts = DiscoverCategory::query()->where('key', 'concerts')->value('id');
        $this->adminEvent('Concert A', 'Riyadh', '2026-11-04 21:00', 'concerts');
        $this->adminEvent('Concert B', 'Jeddah', '2026-11-05 21:00', 'concerts');
        $this->adminEvent('Concert in Dubai', 'Dubai', '2026-11-05 21:00', 'concerts');
        $this->adminEvent('Expo', 'Riyadh', '2026-11-04 10:00', 'exhibitions');
        DiscoverSetting::query()->firstOrFail()->update(['max_alerts_per_week' => 2]);

        $this->as($this->alice);
        $this->putJson('/api/mobile/v1/discover/preferences', ['categories' => [$concerts], 'alert_days' => 7], $this->headers())->assertOk();

        $this->artisan('discover:alerts')->expectsOutputToContain('Sent 1')->assertSuccessful();
        $alerts = $this->pushes('discover.alert');
        $this->assertCount(1, $alerts);
        $this->assertSame(['alice-phone'], $alerts[0]['include_player_ids']);
        $this->assertStringContainsString('Concert A', $alerts[0]['contents']['en']);
        $this->assertDatabaseCount('discover_alert_log', 2);

        // Nothing again — not the same events, and the week's limit is used.
        $this->artisan('discover:alerts')->assertSuccessful();
        $this->assertCount(1, $this->pushes('discover.alert'));
    }

    public function test_the_admin_switches_discover_off_per_country_and_manages_the_catalog(): void
    {
        $this->asAdmin(['discover-settings.update', 'discover-settings.view', 'discover-categories.create', 'discover-cities.view']);
        $this->putJson('/api/admin/v1/discover-settings', ['enabled_countries' => [$this->egypt->id]])->assertOk()->assertJsonPath('data.enabled_countries', [$this->egypt->id]);
        $this->postJson('/api/admin/v1/discover-categories', ['key' => 'gaming', 'emoji' => '🎮', 'translations' => [['locale' => 'en', 'name' => 'Gaming']]])
            ->assertCreated()->assertJsonPath('data.name', 'Gaming');
        $this->assertGreaterThan(5, count($this->getJson('/api/admin/v1/discover-cities')->assertOk()->json('data')));

        $this->as($this->alice);
        $this->getJson('/api/mobile/v1/discover/home', $this->headers())->assertForbidden()->assertJsonPath('error_code', 'discover_off');
        $this->getJson('/api/mobile/v1/discover/home', ['X-Country' => 'EG'])->assertOk();
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @return array<string, mixed>
     */
    private function eventData(string $title, string $startsAt = '2026-11-05 20:00'): array
    {
        return [
            'title' => $title,
            'category_id' => DiscoverCategory::query()->where('key', 'concerts')->value('id'),
            'city_id' => $this->city('Riyadh')->id,
            'venue' => 'Boulevard',
            'starts_at' => $startsAt,
            'is_free' => false,
            'price_text' => 'From 150 SAR',
            'booking_url' => 'https://tickets.example.com/jazz',
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function adminEvent(string $title, string $city, string $startsAt, string $category = 'concerts', array $extra = []): DiscoverEvent
    {
        $this->asAdmin(['discover-events.create'], uniqid('admin').'@example.com');
        $id = $this->postJson('/api/admin/v1/discover-events', [
            'title' => $title,
            'category_id' => DiscoverCategory::query()->where('key', $category)->value('id'),
            'city_id' => $this->city($city)->id,
            'starts_at' => $startsAt,
            'source_url' => 'https://official.example.com',
        ] + $extra + ['is_free' => false])->assertCreated()->json('data.id');

        return DiscoverEvent::query()->where('uuid', $id)->firstOrFail();
    }

    private function city(string $name): DiscoverCity
    {
        return DiscoverCity::query()->whereHas('translations', fn ($q) => $q->where('locale', 'en')->where('name', $name))->firstOrFail();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pushes(string $event): array
    {
        // Pushes from console commands wait in Laravel's deferred callbacks.
        app(\Illuminate\Support\Defer\DeferredCallbackCollection::class)->invoke();

        return collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->filter(fn ($r) => str_contains($r->url(), 'onesignal') && ($r['data']['event'] ?? null) === $event)
            ->map(fn ($r) => $r->data())->values()->all();
    }

    /**
     * @param  list<string>  $permissions
     */
    private function asAdmin(array $permissions, string $email = 'a@example.com'): void
    {
        $admin = \Modules\Admin\Models\Admin::query()->firstOrCreate(['email' => $email], ['name' => 'A', 'password' => 'secret123', 'status' => 'active']);
        foreach ($permissions as $name) {
            \Spatie\Permission\Models\Permission::findOrCreate($name, 'admin_api');
        }
        $admin->givePermissionTo($permissions);
        Sanctum::actingAs($admin, [], 'admin_api');
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
