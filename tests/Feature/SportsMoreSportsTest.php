<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use App\Models\NotificationDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Chat\Models\ChatPrivacySetting;
use Modules\Sports\Data\FightsAdapter;
use Modules\Sports\Database\Seeders\SportsSeeder;
use Modules\Sports\Events\MatchChanged;
use Modules\Sports\Models\SportsCompetition;
use Modules\Sports\Models\SportsMatch;
use Modules\Sports\Models\SportsSport;
use Modules\Sports\Models\SportsTeam;
use Modules\Sports\Services\SportsEngine;
use Modules\Sports\Support\ApiSportsClient;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * DORR Sports beyond football (183): F1 races with their live classification and the drivers'
 * championship; MMA fights with how they were won — and what quiet hours held, summed up once
 * they end (193).
 */
class SportsMoreSportsTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private array $api = [];

    protected function setUp(): void
    {
        parent::setUp();
        $flag = Flag::create(['code' => 'sa']);
        $saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR', 'decimal_places' => 2])->id, 'status' => true]);
        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $code => $direction) {
            Language::create(['code' => $code, 'direction' => $direction, 'is_default_website' => $code === 'en', 'is_default_dashboard' => $code === 'en', 'stores_translation' => true, 'status' => true, 'flag_id' => $flag->id]);
        }
        $this->seed(SportsSeeder::class);
        $this->alice = User::create(['name' => 'Alice', 'phone' => '+966500000001', 'country_id' => $saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        NotificationDevice::query()->create(['owner_type' => User::class, 'owner_id' => $this->alice->id, 'player_id' => 'alice-phone', 'platform' => 'android']);
        config(['services.api_sports.key' => 'k', 'services.api_sports.daily_limit' => 7500, 'services.onesignal.app_id' => 'app', 'services.onesignal.rest_api_key' => 'rest']);
        Http::fake([
            '*.api-sports.io/*' => fn (Request $r) => Http::response(['response' => $this->answer($r), 'errors' => [], 'results' => 1]),
            'api.onesignal.com/*' => Http::response(['id' => 'n1']),
        ]);
        $this->travelTo(Carbon::parse('2026-10-18 13:00', 'UTC'));
    }

    public function test_a_race_has_its_grand_prix_laps_and_live_classification_and_a_drivers_championship(): void
    {
        $f1 = SportsSport::query()->where('key', 'formula1')->firstOrFail();
        $f1->update(['status' => true]);
        app(SportsEngine::class)->importCompetitions($f1);
        $champ = SportsCompetition::query()->where('sport_id', $f1->id)->firstOrFail();
        $champ->update(['tier' => 'big']);
        $this->api['races'] = [$this->race('Scheduled', null)];
        app(SportsEngine::class)->syncSchedule($f1, '2026-10-18');
        $race = SportsMatch::query()->where('sport_id', $f1->id)->firstOrFail();
        $this->assertSame('Bahrain Grand Prix', $race->round);
        $this->assertNull($race->home_team_id);

        // Following the championship brings its races; lights out → live with the order.
        $this->as()->postJson('/api/mobile/v1/sports/follows', ['kind' => 'competition', 'target_id' => $champ->id], ['X-Country' => 'SA'])->assertOk();
        $this->travelTo(Carbon::parse('2026-10-18 15:10', 'UTC'));
        $this->api['races'] = [$this->race('Live', 12)];
        $this->api['rankings/races'] = [$this->rank(1, 'Lando Norris', 'NOR'), $this->rank(2, 'Max Verstappen', 'VER')];
        app(SportsEngine::class)->tick();
        $page = $this->getJson("/api/mobile/v1/sports/matches/{$race->uuid}", ['X-Country' => 'SA'])->assertOk();
        $page->assertJsonPath('data.status', 'live')->assertJsonPath('data.minute', 12)->assertJsonPath('data.title', 'Bahrain Grand Prix')
            ->assertJsonPath('data.race.laps', 57)->assertJsonPath('data.results.0.abbr', 'NOR');
        $this->assertNotNull(collect($this->pushes())->first(fn ($p) => str_contains($p['contents']['en'] ?? '', 'Lights out')));

        // In the calendar too.
        $this->getJson('/api/mobile/v1/chat/calendar?from=2026-10-18&to=2026-10-19&timezone=UTC', ['X-Country' => 'SA'])->assertOk()
            ->assertJsonPath('data.items.0.title', 'Bahrain Grand Prix');

        // The drivers' championship is its table.
        $this->api['rankings/drivers'] = [['position' => 1, 'driver' => ['id' => 49, 'name' => 'Lando Norris', 'abbr' => 'NOR'], 'team' => ['name' => 'McLaren'], 'points' => 300, 'wins' => 7]];
        app(SportsEngine::class)->syncStandings($champ->refresh()->load('sport'));
        $this->getJson("/api/mobile/v1/sports/competitions/{$champ->id}", ['X-Country' => 'SA'])->assertOk()
            ->assertJsonPath('data.standings.0.rows.0.team.name', 'Lando Norris')->assertJsonPath('data.standings.0.rows.0.points', 300)
            ->assertJsonPath('data.standings.0.rows.0.description', 'McLaren');
    }

    public function test_a_fight_has_its_fighters_class_and_how_it_was_won(): void
    {
        $fight = (new FightsAdapter(app(ApiSportsClient::class)))->fight(
            ['id' => 9, 'date' => '2026-10-18T22:00:00+00:00', 'slug' => 'UFC 320', 'category' => 'Lightweight', 'is_main' => true, 'status' => ['short' => 'FT', 'long' => 'Finished'],
                'fighters' => ['first' => ['id' => 1, 'name' => 'Islam Makhachev', 'winner' => true], 'second' => ['id' => 2, 'name' => 'Arman Tsarukyan', 'winner' => false]]],
            ['won_type' => 'Submission', 'round' => 4, 'minute' => '3:05'],
        );
        $this->assertSame(['finished', 'home', 'UFC 320', 'Islam Makhachev'], [$fight['status'], $fight['winner'], $fight['competition']['round'], $fight['home']['name']]);
        $this->assertSame(['Lightweight', 'Submission', 4, '3:05'], [$fight['scores']['fight']['category'], $fight['scores']['fight']['won_by'], $fight['scores']['fight']['round'], $fight['scores']['fight']['time']]);
        $this->assertSame('live', FightsAdapter::status('IN'));
    }

    public function test_alerts_held_by_quiet_hours_come_as_one_summary_when_they_end(): void
    {
        $sport = SportsSport::query()->where('key', 'football')->firstOrFail();
        $comp = SportsCompetition::query()->create(['sport_id' => $sport->id, 'provider_id' => 307, 'name' => 'Pro League', 'tier' => 'big', 'season' => '2026']);
        $home = SportsTeam::query()->create(['sport_id' => $sport->id, 'provider_id' => 1, 'name' => 'Al-Hilal']);
        $away = SportsTeam::query()->create(['sport_id' => $sport->id, 'provider_id' => 2, 'name' => 'Al-Nassr']);
        $match = SportsMatch::query()->create(['uuid' => (string) Str::uuid(), 'sport_id' => $sport->id, 'competition_id' => $comp->id, 'provider_id' => 5,
            'home_team_id' => $home->id, 'away_team_id' => $away->id, 'starts_at' => now(), 'status' => 'live', 'home_score' => 1, 'away_score' => 0]);
        $this->as()->postJson('/api/mobile/v1/sports/follows', ['kind' => 'team', 'target_id' => $home->id], ['X-Country' => 'SA'])->assertOk();

        // 16:00 in Riyadh: quiet from 15:00 to 18:00 — the goal is held, not pushed.
        ChatPrivacySetting::query()->create(['owner_type' => 'user', 'owner_id' => $this->alice->id, 'quiet_schedule' => ['from' => '15:00', 'to' => '18:00', 'timezone' => 'Asia/Riyadh']]);
        event(new MatchChanged($match, [['type' => 'goal', 'side' => 'home', 'home' => 1, 'away' => 0, 'minute' => 30]]));
        $this->assertCount(0, $this->pushes());
        $this->assertSame(1, DB::table('sports_held_alerts')->count());

        // Still quiet: nothing. After 18:00: one summary with the score as it is now.
        $this->artisan('sports:reminders')->assertSuccessful();
        $this->assertCount(0, $this->pushes());
        $match->update(['home_score' => 2, 'status' => 'finished']);
        $this->travelTo(Carbon::parse('2026-10-18 15:05', 'UTC'));
        $this->artisan('sports:reminders')->assertSuccessful();
        $digest = collect($this->pushes())->firstWhere('data.event', 'sports.digest');
        $this->assertNotNull($digest);
        $this->assertStringContainsString('Al-Hilal 2 – 0 Al-Nassr', $digest['contents']['en']);
        $this->assertSame(0, DB::table('sports_held_alerts')->count());
    }

    // ------------------------------------------------------------------ helpers

    private function answer(Request $r): array
    {
        $path = trim((string) parse_url($r->url(), PHP_URL_PATH), '/');

        return $this->api[$path] ?? [];
    }

    private function race(string $status, ?int $lap): array
    {
        return ['id' => 1857, 'competition' => ['id' => 2, 'name' => 'Bahrain Grand Prix', 'location' => ['country' => 'Bahrain', 'city' => 'Sakhir']],
            'circuit' => ['id' => 2, 'name' => 'Bahrain International Circuit', 'image' => null], 'season' => 2026, 'type' => 'Race',
            'laps' => ['current' => $lap, 'total' => 57], 'fastest_lap' => ['driver' => ['id' => null], 'time' => null], 'distance' => '308.5 Kms',
            'timezone' => 'utc', 'date' => '2026-10-18T15:00:00+00:00', 'status' => $status];
    }

    private function rank(int $pos, string $name, string $abbr): array
    {
        return ['race' => ['id' => 1857], 'driver' => ['id' => $pos, 'name' => $name, 'abbr' => $abbr, 'number' => $pos], 'team' => ['id' => 1, 'name' => 'Team'], 'position' => $pos, 'time' => null, 'laps' => 12, 'grid' => '1', 'pits' => 0, 'gap' => null];
    }

    /** @return list<array<string, mixed>> */
    private function pushes(): array
    {
        app(DeferredCallbackCollection::class)->invoke();

        return collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn ($r) => str_contains($r->url(), 'onesignal'))->map(fn ($r) => $r->data())->values()->all();
    }

    private function as(): static
    {
        Sanctum::actingAs($this->alice, [], 'user_api');

        return $this;
    }
}
