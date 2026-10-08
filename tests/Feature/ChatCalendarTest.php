<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use App\Models\NotificationDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Modules\Chat\Models\ChatContact;
use Modules\Chat\Models\ChatPrivacySetting;
use Modules\Chat\Models\ChatSetting;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * DORR Calendar & DORR Today (spec 201–207; AT-CAL-01, AT-CAL-02): only the sources I keep on,
 * the real instant kept while I travel, one event never twice, smart reminders that wait for my
 * quiet hours, one search, and today's page in my order.
 */
class ChatCalendarTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();

        $flag = Flag::create(['code' => 'sa']);
        $saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR', 'decimal_places' => 2])->id, 'status' => true]);
        Language::create(['code' => 'en', 'direction' => 'ltr', 'is_default_website' => true, 'is_default_dashboard' => true, 'stores_translation' => true, 'status' => true, 'flag_id' => $flag->id]);
        $make = fn (string $name, string $phone) => User::create(['name' => $name, 'phone' => $phone, 'country_id' => $saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->alice = $make('Alice', '+966500000001');
        $this->bob = $make('Bob', '+966500000002');
        $this->alice->forceFill(['timezone' => 'Asia/Riyadh'])->save();
        foreach ([[$this->alice, $this->bob], [$this->bob, $this->alice]] as [$owner, $contact]) {
            ChatContact::query()->create(['owner_type' => 'user', 'owner_id' => $owner->id, 'name' => $contact->name, 'phone' => $contact->phone, 'contact_type' => 'user', 'contact_id' => $contact->id, 'source' => 'manual']);
        }

        config(['services.onesignal.app_id' => 'app', 'services.onesignal.rest_api_key' => 'rest']);
        Http::fake(['api.onesignal.com/*' => Http::response(['id' => 'n1'])]);
        NotificationDevice::query()->create(['owner_type' => User::class, 'owner_id' => $this->alice->id, 'player_id' => 'alice-phone', 'platform' => 'android']);
        $this->travelTo(Carbon::parse('2026-10-12 05:00', 'UTC')); // 08:00 in Riyadh, a Monday
    }

    public function test_an_appointment_keeps_its_real_time_when_i_travel_and_is_never_added_twice(): void
    {
        $this->as($this->alice);
        $id = $this->postJson('/api/mobile/v1/chat/calendar/items', ['title' => 'Dentist', 'starts_at' => '2026-10-13 15:00', 'timezone' => 'Asia/Riyadh', 'location' => 'King Road clinic'], $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.starts_at', '2026-10-13T12:00:00+00:00')
            ->assertJsonPath('data.reminders', [30])
            ->json('data.ref');

        // The same event again (from a chat, say): it comes back, not a second one.
        $this->postJson('/api/mobile/v1/chat/calendar/items', ['title' => '  dentist ', 'starts_at' => '2026-10-13T12:00:00Z'], $this->headers())
            ->assertOk()->assertJsonPath('data.duplicate', true)->assertJsonPath('data.ref', $id);
        $this->assertDatabaseCount('chat_calendar_items', 1);

        // In New York the same instant: 08:00 there, still shown as 15:00 in Riyadh.
        $this->getJson('/api/mobile/v1/chat/calendar?from=2026-10-12&to=2026-10-18&timezone=America/New_York', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.items.0.starts_at', '2026-10-13T12:00:00+00:00')
            ->assertJsonPath('data.items.0.date', '2026-10-13')
            ->assertJsonPath('data.items.0.origin_time', '15:00');

        // Moved: same instant logic; all-day keeps its date.
        $this->patchJson("/api/mobile/v1/chat/calendar/items/{$id}", ['all_day' => true, 'date' => '2026-10-14'], $this->headers())
            ->assertOk()->assertJsonPath('data.all_day', true)->assertJsonPath('data.date', '2026-10-14');

        $this->as($this->bob);
        $this->getJson("/api/mobile/v1/chat/calendar/items/{$id}", $this->headers())->assertNotFound();
    }

    public function test_one_calendar_of_only_the_sources_i_keep_on_without_duplicates(): void
    {
        $chat = $this->direct();
        $this->as($this->bob);
        $msg = $this->postJson("/api/mobile/v1/chat/conversations/{$chat}/messages", ['type' => 'text', 'body' => 'Meeting Wednesday 10am'], $this->headers())->json('data.id');

        $this->as($this->alice);
        // From the chat: an appointment, and the same thing as a message reminder — shown once.
        $this->postJson('/api/mobile/v1/chat/calendar/items', ['title' => 'Meeting with Bob', 'starts_at' => '2026-10-14 10:00', 'message_id' => $msg], $this->headers())->assertCreated()->assertJsonPath('data.source', 'chat');
        $this->putJson("/api/mobile/v1/chat/messages/{$msg}/reminder", ['remind_at' => '2026-10-14T07:00:00Z'], $this->headers())->assertOk();
        $this->postJson('/api/mobile/v1/chat/tasks', ['tasks' => [['text' => 'Buy a gift', 'due_at' => '2026-10-15T12:00:00Z']]], $this->headers())->assertCreated();
        $this->postJson('/api/mobile/v1/chat/moments/personal', ['kind' => 'birthday', 'title' => 'Bob', 'month' => 10, 'day' => 16], $this->headers())->assertSuccessful();

        $types = collect($this->getJson('/api/mobile/v1/chat/calendar?from=2026-10-12&to=2026-10-18', $this->headers())->assertOk()->json('data.items'))->pluck('type');
        $this->assertSame(1, $types->filter(fn ($t) => $t === 'event')->count());
        $this->assertNotContains('reminder', $types->all()); // the appointment covers it
        $this->assertContains('task', $types->all());
        $this->assertContains('personal', $types->all());

        // Tasks off: they're gone from the calendar.
        $this->putJson('/api/mobile/v1/chat/calendar/preferences', ['sources' => ['tasks' => false]], $this->headers())->assertOk()->assertJsonPath('data.sources.tasks', false);
        $types = collect($this->getJson('/api/mobile/v1/chat/calendar?from=2026-10-12&to=2026-10-18', $this->headers())->json('data.items'))->pluck('type');
        $this->assertNotContains('task', $types->all());

        $this->getJson('/api/mobile/v1/chat/calendar?from=2026-01-01&to=2026-12-31', $this->headers())->assertStatus(422);
    }

    public function test_dorr_today_in_my_order_with_what_i_might_miss(): void
    {
        $this->as($this->alice);
        $this->postJson('/api/mobile/v1/chat/calendar/items', ['title' => 'Gym', 'starts_at' => '2026-10-12 18:00'], $this->headers())->assertCreated();
        $this->postJson('/api/mobile/v1/chat/calendar/items', ['title' => 'Flight', 'starts_at' => '2026-10-13 06:30'], $this->headers())->assertCreated();
        $this->postJson('/api/mobile/v1/chat/tasks', ['tasks' => [['text' => 'Pay the rent', 'due_at' => '2026-10-10T09:00:00Z']]], $this->headers())->assertCreated();
        $this->postJson('/api/mobile/v1/chat/moments/personal', ['kind' => 'birthday', 'title' => 'Mum', 'month' => 10, 'day' => 15], $this->headers())->assertSuccessful();
        $this->putJson('/api/mobile/v1/chat/calendar/preferences', ['today_sections' => ['order' => ['tasks', 'next'], 'hidden' => ['reminders']]], $this->headers())->assertOk();

        $this->getJson('/api/mobile/v1/chat/calendar/today', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.date', '2026-10-12')
            ->assertJsonPath('data.sections.order.0', 'tasks')
            ->assertJsonPath('data.sections.hidden', ['reminders'])
            ->assertJsonPath('data.next.title', 'Gym')
            ->assertJsonPath('data.tasks.0.title', 'Pay the rent')
            ->assertJsonPath('data.tasks.0.overdue', true)
            ->assertJsonPath('data.around.might_miss.0.kind', 'overdue_tasks')
            ->assertJsonFragment(['kind' => 'personal_soon', 'title' => 'Mum'])
            ->assertJsonFragment(['kind' => 'early_tomorrow', 'title' => 'Flight']);

        // The admin's switch: no calendar, the chat goes on (AT-CORE-01).
        ChatSetting::current()->update(['calendar_enabled' => false]);
        $this->getJson('/api/mobile/v1/chat/calendar/today', $this->headers())->assertOk()->assertJsonPath('data.enabled', false);
        $this->getJson('/api/mobile/v1/chat/conversations', $this->headers())->assertOk();
    }

    public function test_one_search_over_calendar_tasks_dates_and_occasions_never_messages(): void
    {
        $chat = $this->direct();
        $this->as($this->bob);
        $this->postJson("/api/mobile/v1/chat/conversations/{$chat}/messages", ['type' => 'text', 'body' => 'secret dentist gossip'], $this->headers());
        $this->as($this->alice);
        $this->postJson('/api/mobile/v1/chat/calendar/items', ['title' => 'Dentist', 'starts_at' => '2026-10-13 15:00'], $this->headers());
        $this->postJson('/api/mobile/v1/chat/tasks', ['tasks' => [['text' => 'Book the dentist']]], $this->headers());

        $items = collect($this->getJson('/api/mobile/v1/chat/calendar/search?q=dentist', $this->headers())->assertOk()->json('data.items'));
        $this->assertSame(['event', 'task'], $items->pluck('type')->sort()->values()->all());
        $this->assertFalse($items->contains(fn ($i) => str_contains($i['title'], 'gossip')));

        $this->assertNotEmpty($this->getJson('/api/mobile/v1/chat/calendar/search?q=Eid', $this->headers())->json('data.items'));
    }

    public function test_smart_reminders_once_at_my_times_waiting_for_my_quiet_hours(): void
    {
        $this->as($this->alice);
        $this->postJson('/api/mobile/v1/chat/calendar/items', ['title' => 'Dentist', 'starts_at' => '2026-10-12 23:30', 'reminders' => [30, 120]], $this->headers())->assertCreated();
        // Quiet 22:00–07:00 in Riyadh.
        ChatPrivacySetting::query()->updateOrCreate(['owner_type' => 'user', 'owner_id' => $this->alice->id], ['quiet_schedule' => ['from' => '22:00', 'to' => '07:00', 'timezone' => 'Asia/Riyadh']]);

        // 21:31 Riyadh: the 2-hour one is due → one push.
        $this->travelTo(Carbon::parse('2026-10-12 18:31', 'UTC'));
        $this->run2();
        $this->assertCount(1, $this->pushes());

        // 23:01 Riyadh: the 30-minute one is due, but it's quiet → waits.
        $this->travelTo(Carbon::parse('2026-10-12 20:01', 'UTC'));
        $this->run2();
        $this->assertCount(1, $this->pushes());

        // Without "wait for quiet hours": it goes, once.
        $this->putJson('/api/mobile/v1/chat/calendar/preferences', ['respect_quiet' => false], $this->headers())->assertOk();
        $this->run2();
        $this->assertCount(2, $this->pushes());
        $this->assertSame('chat.calendar.reminder', $this->pushes()[1]['data']['event']);

        // All-day: 09:00 where I am.
        $this->postJson('/api/mobile/v1/chat/calendar/items', ['title' => 'Holiday', 'all_day' => true, 'date' => '2026-10-13'], $this->headers())->assertCreated()->assertJsonPath('data.reminders', [0]);
        $this->travelTo(Carbon::parse('2026-10-13 05:59', 'UTC')); // 08:59 Riyadh
        $this->run2();
        $this->assertCount(2, $this->pushes());
        $this->travelTo(Carbon::parse('2026-10-13 06:01', 'UTC'));
        $this->run2();
        $this->assertCount(3, $this->pushes());
    }

    private function run2(): void
    {
        $this->artisan('chat:calendar-reminders')->assertSuccessful();
        $this->artisan('chat:calendar-reminders')->assertSuccessful();
        app(DeferredCallbackCollection::class)->invoke();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pushes(): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])->filter(fn ($r) => ($r['data']['type'] ?? null) === 'calendar')->map(fn ($r) => $r->data())->values()->all();
    }

    private function direct(): string
    {
        $this->as($this->alice);

        return $this->postJson('/api/mobile/v1/chat/conversations/direct', ['participant_id' => $this->bob->id], $this->headers())->assertOk()->json('data.id');
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
