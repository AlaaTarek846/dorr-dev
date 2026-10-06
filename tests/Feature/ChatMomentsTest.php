<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Chat\Models\ChatContact;
use Modules\Chat\Models\ChatMoment;
use Modules\Chat\Models\ChatSetting;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * DORR Moments (spec 157–160, 168; AT-MOM-01, AT-MOM-02): occasions by country, Hijri dates with
 * Umm al-Qura and the admin's corrections, preferences, my own dates.
 */
class ChatMomentsTest extends TestCase
{
    use RefreshDatabase;

    private Country $saudi;

    private Country $egypt;

    private User $sara;

    private User $omar;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $flag = Flag::create(['code' => 'sa']);
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id, 'status' => true]);
        $this->egypt = Country::create(['code' => 'EG', 'dial_code' => '+20', 'phone_length' => 10, 'is_default' => false, 'flag_id' => $flag->id, 'currency_id' => Currency::create(['code' => 'EGP', 'symbol' => 'EGP'])->id, 'status' => true]);
        Language::create(['code' => 'en', 'direction' => 'ltr', 'is_default_website' => true, 'is_default_dashboard' => true, 'stores_translation' => true, 'status' => true, 'flag_id' => $flag->id]);

        $this->sara = User::create(['name' => 'Sara', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->omar = User::create(['name' => 'Omar', 'phone' => '+201000000002', 'country_id' => $this->egypt->id, 'status' => 'active', 'phone_verified_at' => now()]);
    }

    public function test_hijri_occasions_follow_umm_al_qura_and_the_banner_shows_what_is_on(): void
    {
        $this->travelTo(Carbon::parse('2026-03-05 10:00', 'UTC')); // in Ramadan 1447

        $this->as($this->sara);
        $center = $this->getJson('/api/mobile/v1/chat/moments', $this->headers())->assertOk()->assertJsonPath('data.enabled', true);
        $active = collect($center->json('data.active'))->keyBy('key');
        $this->assertTrue($active['ramadan']['is_today']);
        $this->assertSame('2026-02-18', $active['ramadan']['start']);
        $this->assertSame('lanterns', $active['ramadan']['animation']);

        $upcoming = collect($center->json('data.upcoming'))->keyBy('key');
        $this->assertSame('2026-03-20', $upcoming['eid_al_fitr']['start']); // 1 Shawwal 1447
        $this->assertSame(15, $upcoming['eid_al_fitr']['days_left']);
        $this->assertSame('2026-05-27', $upcoming['eid_al_adha']['start']); // 10 Dhu al-Hijjah 1447
    }

    public function test_the_admin_corrects_a_date_for_one_country_and_it_reaches_the_app_at_once(): void
    {
        $this->travelTo(Carbon::parse('2026-03-05 10:00', 'UTC'));
        $eid = ChatMoment::query()->where('key', 'eid_al_fitr')->firstOrFail();

        // Egypt sighted the moon a day later.
        $this->asAdmin(['chat-moments.view', 'chat-moments.update']);
        $this->putJson("/api/admin/v1/chat-moments/{$eid->id}/dates", ['year' => 2026, 'country_id' => $this->egypt->id, 'date' => '2026-03-21'])
            ->assertOk()->assertJsonPath('data.years.0.computed.0', '2026-03-20');

        $start = function (User $who) {
            $this->as($who);

            return collect($this->getJson('/api/mobile/v1/chat/moments', $this->headers())->json('data.upcoming'))->firstWhere('key', 'eid_al_fitr')['start'];
        };
        $this->assertSame('2026-03-21', $start($this->omar));
        $this->assertSame('2026-03-20', $start($this->sara));
    }

    public function test_each_country_sees_its_own_and_people_choose_the_rest(): void
    {
        $this->travelTo(Carbon::parse('2026-09-10 10:00', 'UTC'));
        $keys = function (User $who) {
            $this->as($who);

            return collect($this->getJson('/api/mobile/v1/chat/moments', $this->headers())->json('data.upcoming'))->pluck('key')->all();
        };

        $this->assertContains('national_day_sa', $keys($this->sara));
        $this->assertNotContains('national_day_sa', $keys($this->omar));
        $this->assertContains('october_victory_eg', $keys($this->omar));

        // Christmas isn't on by default; Omar adds it, and turns teachers' day off.
        $this->assertNotContains('christmas', $keys($this->omar));
        $christmas = ChatMoment::query()->where('key', 'christmas')->value('id');
        $teachers = ChatMoment::query()->where('key', 'teachers_day')->value('id');
        $this->as($this->omar);
        $this->putJson('/api/mobile/v1/chat/moments/preferences', ['on' => [$christmas], 'off' => [$teachers], 'effects' => 'light'], $this->headers())
            ->assertOk()->assertJsonPath('data.effects', 'light');
        $this->assertContains('christmas', $keys($this->omar));
        $this->assertNotContains('teachers_day', $keys($this->omar));

        // Omar follows Saudi occasions instead (nothing is inferred; he chose).
        $this->putJson('/api/mobile/v1/chat/moments/preferences', ['country_id' => $this->saudi->id], $this->headers())->assertOk()->assertJsonPath('data.country.code', 'SA');
        $this->assertContains('national_day_sa', $keys($this->omar));
        $catalog = collect($this->getJson('/api/mobile/v1/chat/moments/catalog', $this->headers())->json('data'))->keyBy('key');
        $this->assertTrue($catalog['christmas']['on']);
        $this->assertFalse($catalog['valentines']['on']);
    }

    public function test_switching_moments_off_stops_them_and_the_chat_carries_on(): void
    {
        $this->as($this->sara);
        $this->putJson('/api/mobile/v1/chat/moments/preferences', ['enabled' => false], $this->headers())->assertOk()
            ->assertJsonPath('data.enabled', false)->assertJsonCount(0, 'data.upcoming');

        $this->putJson('/api/mobile/v1/chat/moments/preferences', ['enabled' => true], $this->headers())->assertOk()->assertJsonPath('data.enabled', true);
        ChatSetting::current()->update(['moments_enabled' => false]);
        $this->getJson('/api/mobile/v1/chat/moments', $this->headers())->assertJsonPath('data.enabled', false);

        // AT-MOM-01: messaging is untouched.
        foreach ([[$this->sara, $this->omar], [$this->omar, $this->sara]] as [$owner, $contact]) {
            ChatContact::query()->create(['owner_type' => 'user', 'owner_id' => $owner->id, 'name' => $contact->name, 'phone' => $contact->phone, 'contact_type' => 'user', 'contact_id' => $contact->id, 'source' => 'manual']);
        }
        $chat = $this->postJson('/api/mobile/v1/chat/conversations/direct', ['participant_id' => $this->omar->id], $this->headers())->assertOk()->json('data.id');
        $this->postJson("/api/mobile/v1/chat/conversations/{$chat}/messages", ['type' => 'text', 'body' => 'hi'], $this->headers())->assertCreated();
    }

    public function test_my_own_dates(): void
    {
        $this->travelTo(Carbon::parse('2026-10-07 10:00', 'UTC'));
        $this->as($this->sara);
        $this->postJson('/api/mobile/v1/chat/moments/personal', ['kind' => 'birthday', 'title' => "Omar's birthday", 'month' => 10, 'day' => 12, 'year' => 1996, 'contact_id' => $this->omar->id], $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.0.next', '2026-10-12')->assertJsonPath('data.0.days_left', 5)->assertJsonPath('data.0.turns', 30)
            ->assertJsonPath('data.0.contact.name', 'Omar')->assertJsonPath('data.0.look.emoji', '🎂');
        $this->postJson('/api/mobile/v1/chat/moments/personal', ['kind' => 'other', 'title' => 'x', 'month' => 2, 'day' => 30], $this->headers())
            ->assertUnprocessable()->assertJsonPath('error_code', 'chat_moment_date_invalid');

        $id = $this->getJson('/api/mobile/v1/chat/moments', $this->headers())->json('data.personal.0.id');
        $this->as($this->omar);
        $this->deleteJson("/api/mobile/v1/chat/moments/personal/{$id}", [], $this->headers())->assertNotFound();
        $this->as($this->sara);
        $this->deleteJson("/api/mobile/v1/chat/moments/personal/{$id}", [], $this->headers())->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_admin_adds_an_occasion_with_its_look(): void
    {
        $this->asAdmin(['chat-moments.create', 'chat-moments.view']);
        $this->post('/api/admin/v1/chat-moments', [
            'kind' => 'cultural', 'date_rule' => 'gregorian', 'month' => 4, 'day' => 23, 'countries' => ['sa', 'eg'],
            'theme' => 'culture', 'primary_color' => '#92400E', 'emoji' => '📚', 'animation' => 'sparkles',
            'translations' => [['locale' => 'en', 'name' => 'World Book Day', 'greeting' => 'Happy reading!']],
            'card' => UploadedFile::fake()->image('book.png', 600, 900),
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.countries', ['SA', 'EG'])->assertJsonPath('data.animation', 'sparkles');

        $this->getJson('/api/admin/v1/chat-moments?kind=religious')->assertOk()->assertJsonPath('data.0.key', 'ramadan');
        $this->assertGreaterThan(50, ChatMoment::query()->count());
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param  list<string>  $permissions
     */
    private function asAdmin(array $permissions): void
    {
        $admin = \Modules\Admin\Models\Admin::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'secret123', 'status' => 'active']);
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
