<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use App\Models\NotificationDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\AI\Models\AiProvider;
use Modules\AI\Repositories\AiProviderRepository;
use Modules\Chat\Models\ChatContact;
use Modules\Chat\Models\ChatGroup;
use Modules\Chat\Models\ChatMessage;
use Modules\Discover\Database\Seeders\DiscoverSeeder;
use Modules\Discover\Models\DiscoverCategory;
use Modules\Discover\Models\DiscoverCity;
use Modules\Discover\Models\DiscoverEvent;
use Modules\Discover\Models\DiscoverEventRoom;
use Modules\Discover\Models\DiscoverOrganizer;
use Modules\Discover\Models\DiscoverSetting;
use Modules\User\Models\User;
use Spatie\Permission\Models\Permission;
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
        // The chat's AI tools need a DORR AI plan (2026-10-15): the admin's free trial.
        \Modules\AI\Models\AiPlan::query()->create(['name' => 'Trial', 'code' => 'trial-'.class_basename(static::class), 'is_trial' => true, 'is_active' => true, 'sort_order' => 1, 'usage_minutes' => 600, 'cooldown_minutes' => 5]);
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

    public function test_the_admin_lists_organizers_with_status_counts(): void
    {
        $this->as($this->bob);
        $this->postJson('/api/mobile/v1/discover/organizer', [
            'name' => 'Bob Live',
            'email' => 'bob@live.test',
            'phone' => '+966500000002',
        ], $this->headers())->assertOk();

        $this->as($this->alice);
        $this->postJson('/api/mobile/v1/discover/organizer', ['name' => 'Alice Shows'], $this->headers())->assertOk();
        $alice = DiscoverOrganizer::query()->where('name', 'Alice Shows')->firstOrFail();

        $this->asAdmin(['discover-organizers.view', 'discover-organizers.update']);
        $this->patchJson("/api/admin/v1/discover-organizers/{$alice->id}/review", ['status' => 'verified'])->assertOk();

        $this->getJson('/api/admin/v1/discover-organizers?status_counts=1')
            ->assertOk()
            ->assertJsonPath('status_counts.total', 2)
            ->assertJsonPath('status_counts.pending', 1)
            ->assertJsonPath('status_counts.verified', 1)
            ->assertJsonPath('status_counts.rejected', 0)
            ->assertJsonPath('status_counts.suspended', 0)
            ->assertJsonStructure(['data' => [['id', 'name', 'status', 'created_at', 'updated_at', 'account']], 'pagination']);

        $this->getJson('/api/admin/v1/discover-organizers?status=pending&search=bob@live.test')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Bob Live');
    }

    public function test_the_organizer_hears_the_admins_answer_on_their_phone(): void
    {
        // Phones register under the account's morph class ("user"), like the real app does.
        NotificationDevice::query()->where('player_id', 'bob-phone')->update(['owner_type' => $this->bob->getMorphClass()]);

        $this->as($this->bob);
        $this->postJson('/api/mobile/v1/discover/organizer', ['name' => 'Bob Live'], $this->headers())->assertOk();
        $id = $this->postJson('/api/mobile/v1/discover/organizer/events', $this->eventData('Jazz night'), $this->headers())->assertCreated()->json('data.id');
        $organizer = DiscoverOrganizer::query()->firstOrFail();

        $this->asAdmin(['discover-events.update', 'discover-organizers.update']);
        $this->patchJson("/api/admin/v1/discover-events/{$id}/review", ['review_status' => 'rejected', 'note' => 'Add the venue'])->assertOk();
        $this->patchJson("/api/admin/v1/discover-organizers/{$organizer->id}/review", ['status' => 'verified'])->assertOk();
        // Saving the same answer again tells nobody twice.
        $this->patchJson("/api/admin/v1/discover-organizers/{$organizer->id}/review", ['status' => 'verified'])->assertOk();

        $event = $this->pushes('discover.event.reviewed');
        $this->assertCount(1, $event);
        $this->assertSame(['bob-phone'], $event[0]['include_player_ids']);
        $this->assertSame('rejected', $event[0]['data']['status']);
        $this->assertSame($id, $event[0]['data']['event_id']);
        $this->assertStringContainsString('Add the venue', $event[0]['contents']['en']);

        $organizerPushes = $this->pushes('discover.organizer.reviewed');
        $this->assertCount(1, $organizerPushes);
        $this->assertSame('verified', $organizerPushes[0]['data']['status']);
    }

    // ------------------------------------------------------------------ AT-DISC-01

    public function test_an_organizer_sends_a_cover_with_the_event_and_changes_it_from_the_app(): void
    {
        Storage::fake('public');
        $this->as($this->bob);
        $this->postJson('/api/mobile/v1/discover/organizer', ['name' => 'Bob Live'], $this->headers())->assertOk();

        // Multipart, as the app sends it: the fields (booleans as 1/0) and the photo.
        $data = ['is_free' => '1', 'family_friendly' => '0'] + $this->eventData('Oud night');
        $created = $this->post('/api/mobile/v1/discover/organizer/events', $data + ['cover' => UploadedFile::fake()->image('cover.jpg', 1600, 900)], $this->headers() + ['Accept' => 'application/json'])
            ->assertCreated();
        $id = $created->json('data.id');
        $first = $created->json('data.cover');
        $this->assertNotNull($first);

        // Only a new cover: everything else stays, and it's reviewed again (not verified).
        $updated = $this->post("/api/mobile/v1/discover/organizer/events/{$id}", ['cover' => UploadedFile::fake()->image('new.jpg', 1600, 900)], $this->headers() + ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.title', 'Oud night')->assertJsonPath('data.review_status', 'pending');
        $this->assertNotNull($updated->json('data.cover'));
        $this->assertNotSame($first, $updated->json('data.cover'));

        // Not an image: refused.
        $this->post("/api/mobile/v1/discover/organizer/events/{$id}", ['cover' => UploadedFile::fake()->create('cover.pdf', 10, 'application/pdf')], $this->headers() + ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    public function test_the_organizer_dashboard_pages_their_events_fifteen_at_a_time(): void
    {
        $this->as($this->bob);
        $this->postJson('/api/mobile/v1/discover/organizer', ['name' => 'Bob Live'], $this->headers())->assertOk();
        $organizer = DiscoverOrganizer::query()->firstOrFail();
        $city = $this->city('Riyadh');
        $categoryId = DiscoverCategory::query()->where('key', 'concerts')->value('id');
        foreach (range(1, 16) as $i) {
            $start = Carbon::parse('2026-11-05 20:00', $city->timezone)->addDays($i);
            DiscoverEvent::query()->create([
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'organizer_id' => $organizer->id,
                'source' => 'organizer',
                'title' => "Show {$i}",
                'category_id' => $categoryId,
                'country_id' => $city->country_id,
                'city_id' => $city->id,
                'starts_at' => $start->clone()->utc(),
                'timezone' => $city->timezone,
                'status' => 'confirmed',
                'review_status' => 'pending',
                'dedupe_key' => DiscoverEvent::dedupeKey("Show {$i}", $city->id, $start->toDateString()),
            ]);
        }

        $first = $this->getJson('/api/mobile/v1/discover/organizer?per_page=15', $this->headers())->assertOk();
        $this->assertCount(15, $first->json('data.events'));
        $this->assertTrue($first->json('pagination.has_more_pages'));
        $this->assertSame(16, $first->json('pagination.total'));
        $this->assertSame(15, $first->json('pagination.per_page'));

        $second = $this->getJson('/api/mobile/v1/discover/organizer?per_page=15&page=2', $this->headers())->assertOk();
        $this->assertCount(1, $second->json('data.events'));
        $this->assertFalse($second->json('pagination.has_more_pages'));

        $found = $this->getJson('/api/mobile/v1/discover/organizer?q=Show%2016', $this->headers())->assertOk();
        $this->assertSame(['Show 16'], array_column($found->json('data.events'), 'title'));
        $this->assertFalse($found->json('pagination.has_more_pages'));
    }

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

    public function test_travel_search_also_works_by_country_or_governorate(): void
    {
        $dubai = $this->city('Dubai');
        $this->adminEvent('Dubai night', 'Dubai', '2026-11-21 20:00');
        $this->adminEvent('Riyadh night', 'Riyadh', '2026-11-21 20:00');

        $this->as($this->alice);
        // The whole country (the UAE): only its own events.
        $this->getJson("/api/mobile/v1/discover/travel?country_id={$dubai->country_id}&from=2026-11-21&to=2026-11-22", ['X-Country' => 'EG'])
            ->assertOk()->assertJsonPath('data.place.country_id', $dubai->country_id)->assertJsonCount(1, 'data.items');

        // A governorate: its cities' events.
        $region = \App\Models\Region::query()->create(['country_id' => $dubai->country_id, 'status' => true, 'sort_order' => 1]);
        $dubai->update(['region_id' => $region->id]);
        $this->getJson("/api/mobile/v1/discover/travel?region_id={$region->id}&from=2026-11-21&to=2026-11-22", ['X-Country' => 'EG'])
            ->assertOk()->assertJsonPath('data.place.region_id', $region->id)->assertJsonCount(1, 'data.items');

        // Nothing at all to go on: asked to say where.
        $this->getJson('/api/mobile/v1/discover/travel?from=2026-11-21&to=2026-11-22', ['X-Country' => 'EG'])->assertStatus(422);
    }

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
        $this->assertArrayHasKey('has_more', $sections['this_week']);
        $this->assertFalse($sections['this_week']['has_more']);

        $this->postJson("/api/mobile/v1/discover/events/{$concert->uuid}/interest", [], $this->headers())->assertOk();
        $this->getJson('/api/mobile/v1/chat/calendar?from=2026-11-01&to=2026-11-07&timezone=Asia/Riyadh', $this->headers())->assertOk()
            ->assertJsonPath('data.items.0.type', 'discover')
            ->assertJsonPath('data.items.0.ref', $concert->uuid)
            ->assertJsonPath('data.items.0.date', '2026-11-03');
        $saved = $this->getJson('/api/mobile/v1/discover/interests?per_page=15', $this->headers())->assertOk();
        $this->assertSame(['Big concert'], array_column($saved->json('data'), 'title'));
        $this->assertSame(15, $saved->json('pagination.per_page'));
        $this->assertFalse($saved->json('pagination.has_more_pages'));

        $this->deleteJson("/api/mobile/v1/discover/events/{$concert->uuid}/interest", [], $this->headers())->assertOk();
        $this->assertSame(0, $concert->refresh()->interested_count);
        $this->assertSame([], $this->getJson('/api/mobile/v1/chat/calendar?from=2026-11-01&to=2026-11-07', $this->headers())->json('data.items'));
    }

    public function test_home_this_week_pages_fifteen_at_a_time(): void
    {
        foreach (range(1, 16) as $i) {
            $this->adminEvent("Week {$i}", 'Riyadh', sprintf('2026-11-03 %02d:00', 10 + ($i % 10)));
        }
        $this->as($this->alice);
        $home = $this->getJson('/api/mobile/v1/discover/home', $this->headers())->assertOk();
        $week = collect($home->json('data.sections'))->firstWhere('key', 'this_week');
        $this->assertNotNull($week);
        $this->assertCount(15, $week['items']);
        $this->assertTrue($week['has_more']);
        $page2 = $this->getJson('/api/mobile/v1/discover/sections/this_week?page=2&per_page=15', $this->headers())->assertOk();
        $this->assertCount(1, $page2->json('data'));
        $this->assertFalse($page2->json('pagination.has_more_pages'));
        $this->getJson('/api/mobile/v1/discover/sections/nope', $this->headers())->assertNotFound();
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
        $this->assertNotNull(DiscoverEventRoom::query()->first()->closed_at);
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
        $this->asAdmin(['discover-settings.update', 'discover-settings.view', 'discover-categories.create', 'cities.view']);
        $this->putJson('/api/admin/v1/discover-settings', ['enabled_countries' => [$this->egypt->id]])->assertOk()->assertJsonPath('data.enabled_countries', [$this->egypt->id]);
        $this->postJson('/api/admin/v1/discover-categories', ['key' => 'gaming', 'emoji' => '🎮', 'translations' => [['locale' => 'en', 'name' => 'Gaming']]])
            ->assertCreated()->assertJsonPath('data.name', 'Gaming');
        $this->assertGreaterThan(5, count($this->getJson('/api/admin/v1/discover-cities')->assertOk()->json('data')));

        $this->as($this->alice);
        $this->getJson('/api/mobile/v1/discover/home', $this->headers())->assertForbidden()->assertJsonPath('error_code', 'discover_off');
        $this->getJson('/api/mobile/v1/discover/home', ['X-Country' => 'EG'])->assertOk();
    }

    public function test_the_admin_drags_categories_and_cities_into_a_new_order(): void
    {
        $this->asAdmin(['discover-categories.create', 'discover-categories.update', 'discover-categories.view', 'cities.update', 'cities.view']);

        $a = $this->postJson('/api/admin/v1/discover-categories', ['key' => 'ord_a', 'translations' => [['locale' => 'en', 'name' => 'A']]])->assertCreated()->json('data');
        $b = $this->postJson('/api/admin/v1/discover-categories', ['key' => 'ord_b', 'translations' => [['locale' => 'en', 'name' => 'B']]])->assertCreated()->json('data');
        // No sort_order sent: a new one goes to the end.
        $this->assertGreaterThan($a['sort_order'], $b['sort_order']);

        $this->putJson('/api/admin/v1/discover-categories/reorder', ['ordered_ids' => [$b['id'], $a['id']]])->assertOk();
        $order = collect($this->getJson('/api/admin/v1/discover-categories')->json('data'))->pluck('sort_order', 'id');
        $this->assertSame(1, $order[$b['id']]);
        $this->assertSame(2, $order[$a['id']]);

        $cities = collect($this->getJson('/api/admin/v1/discover-cities')->json('data'))->take(2)->pluck('id')->all();
        $this->putJson('/api/admin/v1/discover-cities/reorder', ['ordered_ids' => array_reverse($cities)])->assertOk();
        $after = collect($this->getJson('/api/admin/v1/discover-cities')->json('data'))->pluck('sort_order', 'id');
        $this->assertSame(1, $after[$cities[1]]);
        $this->assertSame(2, $after[$cities[0]]);
    }

    public function test_app_categories_page_fifteen_at_a_time(): void
    {
        $this->asAdmin(['discover-categories.create', 'discover-categories.view']);
        foreach (range(1, 6) as $i) {
            $this->postJson('/api/admin/v1/discover-categories', ['key' => "extra_{$i}", 'translations' => [['locale' => 'en', 'name' => "Extra {$i}"]]])->assertCreated();
        }
        $this->as($this->alice);
        $page1 = $this->getJson('/api/mobile/v1/discover/categories?per_page=15', $this->headers())->assertOk();
        $this->assertCount(15, $page1->json('data'));
        $this->assertTrue($page1->json('pagination.has_more_pages'));
        $page2 = $this->getJson('/api/mobile/v1/discover/categories?per_page=15&page=2', $this->headers())->assertOk();
        $this->assertNotEmpty($page2->json('data'));
        $this->assertFalse($page2->json('pagination.has_more_pages'));
    }

    public function test_the_admin_deletes_several_catalog_rows_at_once_and_the_ones_in_use_are_skipped(): void
    {
        $this->asAdmin(['discover-categories.create', 'discover-categories.view', 'discover-categories.multiple-delete', 'cities.view', 'cities.multiple-delete']);

        $ids = [];
        foreach (['bulk_a', 'bulk_b'] as $key) {
            $ids[] = $this->postJson('/api/admin/v1/discover-categories', ['key' => $key, 'translations' => [['locale' => 'en', 'name' => $key]]])->assertCreated()->json('data.id');
        }
        $this->postJson('/api/admin/v1/discover-categories/delete-multiple', ['ids' => $ids])->assertOk()->assertJsonPath('data.deleted', 2)->assertJsonPath('data.skipped', 0);
        $this->assertSame(0, \Modules\Discover\Models\DiscoverCategory::query()->whereIn('id', $ids)->count());

        // A city with an event is skipped, never deleted.
        $cityId = \Modules\Discover\Models\DiscoverEvent::query()->value('city_id');
        if ($cityId) {
            $this->postJson('/api/admin/v1/discover-cities/delete-multiple', ['ids' => [$cityId]])->assertOk()->assertJsonPath('data.skipped', 1);
            $this->assertNotNull(\Modules\Discover\Models\DiscoverCity::query()->find($cityId));
        }
    }

    public function test_a_city_can_name_its_governorate_but_only_one_of_its_own_country(): void
    {
        $this->asAdmin(['cities.create', 'cities.update', 'cities.view']);
        $own = \App\Models\Region::query()->where('country_id', $this->egypt->id)->first() ?? \App\Models\Region::query()->create(['country_id' => $this->egypt->id, 'status' => true, 'sort_order' => 1]);
        $other = \App\Models\Region::query()->where('country_id', '!=', $this->egypt->id)->first() ?? \App\Models\Region::query()->create(['country_id' => \App\Models\Country::query()->where('id', '!=', $this->egypt->id)->value('id'), 'status' => true, 'sort_order' => 1]);
        $body = ['country_id' => $this->egypt->id, 'timezone' => 'Africa/Cairo', 'translations' => [['locale' => 'en', 'name' => 'Tanta']]];

        $this->postJson('/api/admin/v1/discover-cities', $body + ['region_id' => $other->id])->assertStatus(422);
        $id = $this->postJson('/api/admin/v1/discover-cities', $body + ['region_id' => $own->id])->assertCreated()->assertJsonPath('data.region_id', $own->id)->json('data.id');

        // Moving the city to another country without a new governorate clears the old one.
        $this->putJson("/api/admin/v1/discover-cities/{$id}", ['country_id' => $other->country_id])->assertOk()->assertJsonPath('data.region_id', null);
    }

    public function test_the_app_browses_by_region_in_batches_and_filters_by_country_region_and_city(): void
    {
        $riyadhRegion = \App\Models\Region::query()->create(['country_id' => $this->saudi->id, 'status' => true, 'sort_order' => 1]);
        $makkah = \App\Models\Region::query()->create(['country_id' => $this->saudi->id, 'status' => true, 'sort_order' => 2]);
        $empty = \App\Models\Region::query()->create(['country_id' => $this->saudi->id, 'status' => true, 'sort_order' => 3]);
        $this->city('Riyadh')->update(['region_id' => $riyadhRegion->id]);
        $this->city('Jeddah')->update(['region_id' => $makkah->id]);
        $this->adminEvent('Big concert', 'Riyadh', '2026-11-03 21:00');
        $this->adminEvent('Expo', 'Riyadh', '2026-11-04 10:00', 'exhibitions');
        $this->adminEvent('Sea fest', 'Jeddah', '2026-11-05 18:00');

        $this->as($this->alice);
        // Home: the regions with something on, where I am — the empty one is left out.
        $home = $this->getJson('/api/mobile/v1/discover/home', $this->headers())->assertOk()->json('data.regions');
        $this->assertSame([$riyadhRegion->id, $makkah->id], array_column($home['items'], 'id'));
        $this->assertSame([2, 1], array_column($home['items'], 'events_count'));
        $this->assertNotContains($empty->id, array_column($home['items'], 'id'));

        // In batches.
        $first = $this->getJson('/api/mobile/v1/discover/regions?per_page=1', $this->headers())->assertOk();
        $first->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $riyadhRegion->id);
        $this->getJson('/api/mobile/v1/discover/regions?per_page=1&page=2', $this->headers())->assertOk()->assertJsonPath('data.0.id', $makkah->id);

        // The filter's steps: countries → that country's catalog regions → the region's cities.
        $countryIds = array_column($this->getJson('/api/mobile/v1/discover/countries', $this->headers())->assertOk()->json('data'), 'id');
        $this->assertContains($this->saudi->id, $countryIds);
        $this->assertContains($this->egypt->id, $countryIds);
        $catalog = $this->getJson("/api/mobile/v1/discover/regions?country_id={$this->saudi->id}&with_events=0&per_page=50", $this->headers())->assertOk()->json('data');
        $this->assertContains($empty->id, array_column($catalog, 'id'));
        $this->assertSame(['Riyadh'], array_column($this->getJson("/api/mobile/v1/discover/cities?region_id={$riyadhRegion->id}&with_events=1", $this->headers())->assertOk()->json('data'), 'name'));

        // Browsing: by region, by city, a page at a time.
        $this->assertEqualsCanonicalizing(['Big concert', 'Expo'], array_column($this->getJson("/api/mobile/v1/discover/events?region_id={$riyadhRegion->id}", $this->headers())->assertOk()->json('data'), 'title'));
        $this->assertSame(['Sea fest'], array_column($this->getJson("/api/mobile/v1/discover/events?region_id={$makkah->id}", $this->headers())->json('data'), 'title'));
        $page = $this->getJson("/api/mobile/v1/discover/events?country_id={$this->saudi->id}&per_page=2", $this->headers())->assertOk();
        $this->assertCount(2, $page->json('data'));
        $this->assertCount(1, $this->getJson("/api/mobile/v1/discover/events?country_id={$this->saudi->id}&per_page=2&page=2", $this->headers())->json('data'));
    }

    public function test_following_a_region_follows_its_cities_events_on_my_home(): void
    {
        $dubaiRegion = \App\Models\Region::query()->create(['country_id' => $this->emirates->id, 'status' => true, 'sort_order' => 1]);
        $dubaiRegion->translations()->create(['locale' => 'en', 'name' => 'Dubai Emirate']);
        $this->city('Dubai')->update(['region_id' => $dubaiRegion->id]);
        $this->adminEvent('Dubai fair', 'Dubai', '2026-11-05 18:00', 'exhibitions');

        $this->as($this->alice);
        $followed = fn () => collect($this->getJson('/api/mobile/v1/discover/home', $this->headers())->assertOk()->json('data.sections'))->keyBy('key');
        $this->assertArrayNotHasKey('followed', $followed()->all());

        $this->postJson('/api/mobile/v1/discover/follows', ['kind' => 'region', 'target_id' => 999999], $this->headers())->assertStatus(404);
        $this->postJson('/api/mobile/v1/discover/follows', ['kind' => 'region', 'target_id' => $dubaiRegion->id], $this->headers())->assertOk()
            ->assertJsonPath('data.0.kind', 'region')->assertJsonPath('data.0.name', 'Dubai Emirate');
        $this->assertSame(['Dubai fair'], array_column($followed()['followed']['items'], 'title'));
    }

    public function test_the_admin_removes_an_events_cover(): void
    {
        Storage::fake('public');
        $this->asAdmin(['discover-events.create', 'discover-events.update']);
        $headers = ['Accept' => 'application/json'];
        $id = $this->post('/api/admin/v1/discover-events', $this->eventData('Jazz night') + ['cover' => UploadedFile::fake()->image('c.jpg', 800, 600)], $headers)
            ->assertCreated()->json('data.id');

        $this->post("/api/admin/v1/discover-events/{$id}", ['remove_cover' => '0'], $headers)->assertOk()->assertJsonPath('data.cover', fn ($v) => $v !== null);
        $this->post("/api/admin/v1/discover-events/{$id}", ['remove_cover' => '1'], $headers)->assertOk()->assertJsonPath('data.cover', null);
    }

    public function test_the_admin_places_an_event_by_country_region_and_city_that_depend_on_each_other(): void
    {
        $region = \App\Models\Region::query()->create(['country_id' => $this->saudi->id, 'status' => true, 'sort_order' => 1]);
        $other = \App\Models\Region::query()->create(['country_id' => $this->saudi->id, 'status' => true, 'sort_order' => 2]);
        $riyadh = $this->city('Riyadh');
        $riyadh->update(['region_id' => $region->id]);
        $jeddah = $this->city('Jeddah');
        $this->asAdmin(['discover-events.create', 'discover-events.view']);
        $body = $this->eventData('Jazz night') + ['country_id' => $this->saudi->id];

        // A city that is not in the chosen region, or not in the chosen country, is refused.
        $this->postJson('/api/admin/v1/discover-events', $body + ['region_id' => $other->id])->assertStatus(422)->assertJsonValidationErrors('city_id');
        $this->postJson('/api/admin/v1/discover-events', ['country_id' => $this->egypt->id] + $body)->assertStatus(422)->assertJsonValidationErrors('city_id');
        $this->postJson('/api/admin/v1/discover-events', $body + ['region_id' => 0])->assertStatus(422);

        $this->postJson('/api/admin/v1/discover-events', $body + ['region_id' => $region->id])->assertCreated()
            ->assertJsonPath('data.country_id', $this->saudi->id)->assertJsonPath('data.region_id', $region->id);
        // A city with no region sits under region 0.
        $this->postJson('/api/admin/v1/discover-events', ['title' => 'Sea fest', 'city_id' => $jeddah->id, 'region_id' => 0] + $body)->assertCreated()->assertJsonPath('data.region_id', null);

        // Filtering by region keeps only its cities' events; 0 is "no region".
        $this->getJson('/api/admin/v1/discover-events?region_id='.$region->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Jazz night')
            ->assertJsonPath('data.0.region_id', $region->id)->assertJsonPath('data.0.country_id', $this->saudi->id);
        $this->getJson('/api/admin/v1/discover-events?region_id=0')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Sea fest');
        $this->getJson('/api/admin/v1/discover-events?country_id='.$this->saudi->id)->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_a_paid_event_shows_the_country_currency_and_how_far_it_is(): void
    {
        $egp = Currency::query()->create(['code' => 'EGP', 'symbol' => 'ج.م', 'decimal_places' => 2]);
        $this->egypt->update(['currency_id' => $egp->id]);
        $event = $this->adminEvent('Pyramids night', 'Cairo', '2026-11-03 20:00', 'concerts', [
            'is_free' => false,
            'price_text' => '300',
            'lat' => 29.9792,
            'lng' => 31.1342,
        ]);
        $this->as($this->alice);
        $this->getJson("/api/mobile/v1/discover/events/{$event->uuid}", $this->headers())
            ->assertOk()
            ->assertJsonPath('data.price_text', '300')
            ->assertJsonPath('data.price_display', '300 ج.م')
            ->assertJsonPath('data.currency.code', 'EGP')
            ->assertJsonPath('data.currency.symbol', 'ج.م');
        $list = $this->getJson('/api/mobile/v1/discover/events?country_id='.$this->egypt->id.'&lat=30.0444&lng=31.2357', $this->headers())->assertOk();
        $this->assertSame('300 ج.م', $list->json('data.0.price_display'));
        $this->assertNotNull($list->json('data.0.distance_km'));
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
        app(DeferredCallbackCollection::class)->invoke();

        return collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->filter(fn ($r) => str_contains($r->url(), 'onesignal') && ($r['data']['event'] ?? null) === $event)
            ->map(fn ($r) => $r->data())->values()->all();
    }

    /**
     * @param  list<string>  $permissions
     */
    private function asAdmin(array $permissions, string $email = 'a@example.com'): void
    {
        $admin = Admin::query()->firstOrCreate(['email' => $email], ['name' => 'A', 'password' => 'secret123', 'status' => 'active']);
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'admin_api');
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
