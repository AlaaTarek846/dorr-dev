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
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\Sports\Data\GamesAdapter;
use Modules\Sports\Database\Seeders\SportsSeeder;
use Modules\Sports\Events\SportsRealtimeEvent;
use Modules\Sports\Models\SportsCompetition;
use Modules\Sports\Models\SportsMatch;
use Modules\Sports\Models\SportsSetting;
use Modules\Sports\Models\SportsSport;
use Modules\Sports\Models\SportsTeam;
use Modules\Sports\Services\SportsEngine;
use Modules\Sports\Services\SportsGovernor;
use Modules\Sports\Support\ApiSportsClient;
use Modules\User\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * DORR Sports (spec 183–200, docs/sports-plan.md): competitions from the provider with tiers, a
 * few schedule requests, live polls only in match windows at the tier's interval (stretched to fit
 * the budget), details only when something changed, alerts to the followers by their own choices
 * (no spoilers, never twice), standings after a round — and the provider never called by the app.
 */
class SportsTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    /** What the fake provider answers, by endpoint. */
    private array $api = [];

    /** The provider's errors, by endpoint (a plan refusal…). */
    private array $errors = [];

    protected function setUp(): void
    {
        parent::setUp();

        $flag = Flag::create(['code' => 'sa']);
        Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR', 'decimal_places' => 2])->id, 'status' => true]);
        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $code => $direction) {
            Language::create(['code' => $code, 'direction' => $direction, 'is_default_website' => $code === 'en', 'is_default_dashboard' => $code === 'en', 'stores_translation' => true, 'status' => true, 'flag_id' => $flag->id]);
        }
        $saudi = Country::query()->firstOrFail();
        $make = fn (string $name, string $phone) => User::create(['name' => $name, 'phone' => $phone, 'country_id' => $saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->alice = $make('Alice', '+966500000001');
        $this->bob = $make('Bob', '+966500000002');
        foreach (['alice' => $this->alice, 'bob' => $this->bob] as $name => $u) {
            NotificationDevice::query()->create(['owner_type' => User::class, 'owner_id' => $u->id, 'player_id' => $name.'-phone', 'platform' => 'android']);
        }

        $this->seed(SportsSeeder::class);
        config(['services.api_sports.key' => 'test-key', 'services.api_sports.daily_limit' => 7500, 'services.onesignal.app_id' => 'app', 'services.onesignal.rest_api_key' => 'rest']);
        Http::fake([
            '*.api-sports.io/*' => fn (Request $r) => Http::response(['response' => $this->answer($r), 'errors' => $this->errors[trim((string) parse_url($r->url(), PHP_URL_PATH), '/')] ?? [], 'results' => 1], 200, ['x-ratelimit-requests-limit' => '7500']),
            'api.onesignal.com/*' => Http::response(['id' => 'n1']),
        ]);
        $this->travelTo(Carbon::parse('2026-10-17 15:00', 'UTC'));

        $this->api['leagues'] = [
            $this->league(307, 'Pro League', 'Saudi-Arabia', 'SA'),
            $this->league(999, 'Tiny League', 'Nowhere', 'NW'),
        ];
        $this->api['fixtures?date'] = [
            $this->fixture(1001, 307, [2932, 'Al-Hilal'], [2939, 'Al-Nassr'], '2026-10-17T17:00:00+00:00'),
            $this->fixture(1002, 307, [2933, 'Al-Ittihad'], [2934, 'Al-Ahli'], '2026-10-17T19:00:00+00:00'),
            $this->fixture(1003, 999, [7001, 'Nobody FC'], [7002, 'Nowhere FC'], '2026-10-17T17:00:00+00:00'),
        ];
    }

    // ------------------------------------------------------------------ 184, 190

    public function test_competitions_come_from_the_provider_and_only_active_ones_are_synced(): void
    {
        $sport = SportsSport::query()->where('key', 'football')->firstOrFail();
        $result = app(SportsEngine::class)->importCompetitions($sport);
        $this->assertSame(['created' => 2, 'updated' => 0], $result);
        // Suggested competitions start "big", the rest off — the admin decides.
        $this->assertSame('big', SportsCompetition::query()->where('provider_id', 307)->value('tier'));
        $this->assertSame('off', SportsCompetition::query()->where('provider_id', 999)->value('tier'));
        $this->assertSame('domestic', SportsCompetition::query()->where('provider_id', 307)->value('scope'));

        // A week of schedules: one request a day for the whole sport, only active competitions stored.
        app(SportsEngine::class)->syncDueSchedules();
        $this->assertSame(7, $this->requests('fixtures'));
        $this->assertSame(2, SportsMatch::query()->count());
        $this->assertEqualsCanonicalizing(['Al-Hilal', 'Al-Nassr', 'Al-Ittihad', 'Al-Ahli'], SportsTeam::query()->pluck('name')->all());

        // Again within 3 hours: nothing is due.
        app(SportsEngine::class)->syncDueSchedules();
        $this->assertSame(7, $this->requests('fixtures'));
    }

    // ------------------------------------------------------------------ 191, 193, 197, 200

    public function test_live_polls_only_in_the_window_at_the_tier_interval_and_followers_get_the_goal(): void
    {
        Event::fake([SportsRealtimeEvent::class]);
        $this->bootstrapSchedule();
        $hilal = SportsTeam::query()->where('name', 'Al-Hilal')->firstOrFail();
        $this->as($this->alice)->postJson('/api/mobile/v1/sports/follows', ['kind' => 'team', 'target_id' => $hilal->id], $this->headers())->assertOk()->assertJsonPath('data.0.target.name', 'Al-Hilal');
        $this->as($this->bob)->postJson('/api/mobile/v1/sports/follows', ['kind' => 'team', 'target_id' => $hilal->id, 'no_spoilers' => true], $this->headers())->assertOk();
        $before = $this->requests('fixtures');

        // Two hours before kick-off: outside every window — no request at all.
        app(SportsEngine::class)->tick();
        $this->assertSame($before, $this->requests('fixtures'));

        // Kick-off and a goal (the live answer carries the event feed).
        $this->travelTo(Carbon::parse('2026-10-17 17:12', 'UTC'));
        $this->api['fixtures?live'] = [$this->fixture(1001, 307, [2932, 'Al-Hilal'], [2939, 'Al-Nassr'], '2026-10-17T17:00:00+00:00', '1H', 12, [1, 0], [
            ['time' => ['elapsed' => 11, 'extra' => null], 'team' => ['id' => 2932], 'player' => ['name' => 'Mitrovic'], 'assist' => ['name' => 'Neves'], 'type' => 'Goal', 'detail' => 'Normal Goal'],
        ])];
        $r = app(SportsEngine::class)->tick();
        $this->assertSame(1, $r['polls']);
        $match = SportsMatch::query()->where('provider_id', 1001)->firstOrFail();
        $this->assertSame('live', $match->status);
        $this->assertSame([1, 0], [$match->home_score, $match->away_score]);
        $this->assertSame('Mitrovic', $match->events()->first()->player);
        Event::assertDispatched(SportsRealtimeEvent::class, fn ($e) => in_array('sports.match.'.$match->uuid, $e->channels, true));

        // Alice: who scored, with the score. Bob (no spoilers): no score.
        $pushes = $this->pushes();
        $alice = collect($pushes)->first(fn ($p) => in_array('alice-phone', $p['include_player_ids'], true) && str_contains($p['contents']['en'] ?? '', 'Mitrovic'));
        $this->assertNotNull($alice);
        $this->assertStringContainsString('1 – 0', $alice['contents']['en']);
        $bob = collect($pushes)->first(fn ($p) => in_array('bob-phone', $p['include_player_ids'], true) && ($p['data']['event'] ?? '') === 'sports.goal_detail');
        $this->assertStringNotContainsString('1 – 0', $bob['contents']['en']);

        // Within the big tier's minute: no new request; the same goal is never sent twice.
        $this->travelTo(Carbon::parse('2026-10-17 17:12:30', 'UTC'));
        app(SportsEngine::class)->tick();
        $this->assertSame(1, $this->requests('fixtures', 'live'));
        $this->travelTo(Carbon::parse('2026-10-17 17:13:10', 'UTC'));
        app(SportsEngine::class)->tick();
        $this->assertSame(2, $this->requests('fixtures', 'live'));
        $this->assertCount(count($pushes), $this->pushes());

        // It leaves the live answer: one details call finds it finished; the table is due.
        $this->travelTo(Carbon::parse('2026-10-17 19:00', 'UTC'));
        $this->api['fixtures?live'] = [];
        $this->api['fixtures?ids'] = [$this->fixture(1001, 307, [2932, 'Al-Hilal'], [2939, 'Al-Nassr'], '2026-10-17T17:00:00+00:00', 'FT', 90, [2, 0], [], true)];
        app(SportsEngine::class)->tick();
        $match->refresh();
        $this->assertSame('finished', $match->status);
        $this->assertSame('home', $match->winner);
        $this->assertTrue(SportsCompetition::query()->where('provider_id', 307)->value('standings_dirty'));
        $this->assertNotNull(collect($this->pushes())->first(fn ($p) => ($p['data']['event'] ?? '') === 'sports.finished'));
    }

    public function test_the_governor_stretches_intervals_to_fit_a_small_budget(): void
    {
        $this->bootstrapSchedule();
        $sport = SportsSport::query()->where('key', 'football')->firstOrFail();
        $governor = app(SportsGovernor::class);
        $this->travelTo(Carbon::parse('2026-10-17 16:58', 'UTC'));

        // 7,500 a day: the big tier's own minute.
        $this->assertSame(60, $governor->interval($sport, 'big'));

        // 30 a day: the same two matches would need more — the minute stretches.
        SportsSetting::query()->firstOrFail()->update(['daily_limit' => 30]);
        $governor->forget();
        $this->assertGreaterThan(60, $governor->interval($sport, 'big'));

        // Nothing left above the reserve: the live loop makes no request.
        DB::table('sports_api_usage')->where(['date' => now()->toDateString(), 'sport_key' => 'football', 'endpoint' => 'fixtures'])->update(['requests' => 27]);
        $governor->forget();
        $this->travelTo(Carbon::parse('2026-10-17 17:10', 'UTC'));
        $this->api['fixtures?live'] = [];
        $this->assertSame(0, app(SportsEngine::class)->tick()['polls']);
    }

    // ------------------------------------------------------------------ app API (189–192, 194)

    public function test_the_app_shows_my_day_the_match_page_the_table_and_saves_my_choices(): void
    {
        $this->bootstrapSchedule();
        $hilal = SportsTeam::query()->where('name', 'Al-Hilal')->firstOrFail();
        $this->as($this->alice);
        $this->postJson('/api/mobile/v1/sports/follows', ['kind' => 'team', 'target_id' => $hilal->id, 'alerts' => ['half_time' => true]], $this->headers())
            ->assertOk()->assertJsonPath('data.0.alerts.half_time', true)->assertJsonPath('data.0.alerts.goal', true);

        $home = $this->getJson('/api/mobile/v1/sports/home?timezone=Asia/Riyadh', $this->headers())->assertOk();
        $home->assertJsonPath('data.mine.0.home.name', 'Al-Hilal')
            ->assertJsonPath('data.mine.0.local_time', '20:00')
            ->assertJsonPath('data.competitions.0.competition.name', 'Pro League')
            ->assertJsonCount(2, 'data.competitions.0.matches');

        $uuid = $home->json('data.mine.0.id');
        $this->getJson("/api/mobile/v1/sports/matches/{$uuid}", $this->headers())->assertOk()
            ->assertJsonPath('data.following.home', true)->assertJsonPath('data.channel', 'sports.match.'.$uuid);

        // The table: the provider's rows, with the trend after the next sync.
        $this->api['standings'] = [['league' => ['standings' => [[
            $this->standing(1, [2932, 'Al-Hilal'], 9), $this->standing(2, [2939, 'Al-Nassr'], 7),
        ]]]]];
        $competition = SportsCompetition::query()->where('provider_id', 307)->firstOrFail();
        app(SportsEngine::class)->syncStandings($competition->load('sport'));
        $this->api['standings'] = [['league' => ['standings' => [[
            $this->standing(1, [2939, 'Al-Nassr'], 10), $this->standing(2, [2932, 'Al-Hilal'], 9),
        ]]]]];
        app(SportsEngine::class)->syncStandings($competition->refresh()->load('sport'));
        $this->getJson("/api/mobile/v1/sports/competitions/{$competition->id}", $this->headers())->assertOk()
            ->assertJsonPath('data.standings.0.rows.0.team.name', 'Al-Nassr')
            ->assertJsonPath('data.standings.0.rows.0.trend', 'up')
            ->assertJsonPath('data.standings.0.rows.1.trend', 'down');

        $this->putJson('/api/mobile/v1/sports/preferences', ['celebration' => 'festive', 'no_spoilers' => true], $this->headers())->assertOk()
            ->assertJsonPath('data.celebration', 'festive')->assertJsonPath('data.no_spoilers', true);
        $this->getJson('/api/mobile/v1/sports/teams?q=hil', $this->headers())->assertOk()->assertJsonPath('data.0.name', 'Al-Hilal');

        // In DORR Calendar: the matches of my teams.
        $this->getJson('/api/mobile/v1/chat/calendar?from=2026-10-17&to=2026-10-18&timezone=Asia/Riyadh', $this->headers())->assertOk()
            ->assertJsonPath('data.items.0.type', 'sports')->assertJsonPath('data.items.0.title', 'Al-Hilal – Al-Nassr');

        // Off here → the app says so.
        SportsSetting::query()->firstOrFail()->update(['enabled' => false]);
        $this->getJson('/api/mobile/v1/sports/home', $this->headers())->assertForbidden()->assertJsonPath('error_code', 'sports_off');
    }

    // ------------------------------------------------------------------ admin, other sports

    public function test_a_match_page_reads_missing_details_once_and_tables_the_plan_refuses_back_off(): void
    {
        $this->bootstrapSchedule();
        $this->as($this->alice);
        $match = SportsMatch::query()->where('provider_id', 1001)->firstOrFail();
        $match->update(['status' => 'finished', 'home_score' => 1, 'away_score' => 0, 'finished_at' => now()]);
        $goal = ['time' => ['elapsed' => 30, 'extra' => null], 'team' => ['id' => 2932], 'player' => ['name' => 'Mitrovic'], 'assist' => ['name' => null], 'type' => 'Goal', 'detail' => 'Normal Goal'];
        $this->api['fixtures?ids'] = [$this->fixture(1001, 307, [2932, 'Al-Hilal'], [2939, 'Al-Nassr'], '2026-10-17T13:00:00+00:00', 'FT', 90, [1, 0], [$goal], full: true)];

        // Opened: events and statistics are read now, once.
        $this->getJson("/api/mobile/v1/sports/matches/{$match->uuid}", $this->headers())->assertOk()
            ->assertJsonPath('data.events.0.player', 'Mitrovic')->assertJsonPath('data.statistics.0.type', 'Ball Possession');
        $this->getJson("/api/mobile/v1/sports/matches/{$match->uuid}", $this->headers())->assertOk();
        $this->assertSame(1, $this->requests('fixtures', 'id'));

        // The plan refuses this season's table: the app hears "unavailable", and nobody asks again.
        $this->errors['standings'] = ['plan' => 'Free plans do not have access to this season, try from 2022 to 2024.'];
        $competition = SportsCompetition::query()->where('provider_id', 307)->firstOrFail();
        app(SportsEngine::class)->syncDueStandings();
        $competition->forceFill(['standings_synced_at' => now()->subDays(2)])->save();
        app(SportsEngine::class)->syncDueStandings();
        $this->assertSame(1, $this->requests('standings'));
        $this->getJson("/api/mobile/v1/sports/competitions/{$competition->id}", $this->headers())->assertOk()
            ->assertJsonPath('data.standings_state', 'unavailable')
            ->assertJsonCount(4, 'data.teams');
    }

    public function test_the_admin_tiers_competitions_and_sees_the_budget(): void
    {
        $this->bootstrapSchedule();
        $this->asAdmin(['sports-competitions.view', 'sports-competitions.update', 'sports-settings.update', 'sports-usage.view']);
        $tiny = SportsCompetition::query()->where('provider_id', 999)->firstOrFail();
        $this->patchJson("/api/admin/v1/sports-competitions/{$tiny->id}", ['tier' => 'minor', 'translations' => [['locale' => 'ar', 'name' => 'الدوري الصغير']]])->assertOk()
            ->assertJsonPath('data.tier', 'minor');
        $this->getJson('/api/admin/v1/sports-competitions?tier=active')->assertOk()->assertJsonCount(2, 'data');
        $this->putJson('/api/admin/v1/sports-settings', ['tier_seconds' => ['normal' => 180, 'minor' => 600], 'sports' => [['key' => 'basketball', 'status' => true]]])->assertOk()
            ->assertJsonPath('data.tier_seconds.minor', 600)->assertJsonPath('data.sports.1.status', true);
        $this->getJson('/api/admin/v1/sports-usage')->assertOk()->assertJsonPath('data.limit', 7500)->assertJsonPath('data.used', 8);
    }

    public function test_games_sports_share_the_same_shape(): void
    {
        $adapter = new GamesAdapter(app(ApiSportsClient::class), 'basketball');
        $game = $adapter->game([
            'id' => 55, 'date' => '2026-10-17T18:00:00+00:00', 'status' => ['short' => 'Q3', 'long' => 'Quarter 3', 'timer' => '7'],
            'league' => ['id' => 12, 'name' => 'NBA', 'season' => '2026-2027'], 'country' => ['name' => 'USA'],
            'teams' => ['home' => ['id' => 1, 'name' => 'Lakers'], 'away' => ['id' => 2, 'name' => 'Celtics']],
            'scores' => [
                'home' => ['quarter_1' => 30, 'quarter_2' => 25, 'quarter_3' => 10, 'quarter_4' => null, 'over_time' => null, 'total' => 65],
                'away' => ['quarter_1' => 28, 'quarter_2' => 27, 'quarter_3' => 8, 'quarter_4' => null, 'over_time' => null, 'total' => 63],
            ],
        ]);
        $this->assertSame('live', $game['status']);
        $this->assertSame([65, 63], [$game['home_score'], $game['away_score']]);
        $this->assertSame(['Q1', 'Q2', 'Q3'], array_column($game['scores']['periods'], 'label'));
        $this->assertSame('finished', GamesAdapter::status('AOT'));
    }

    // ------------------------------------------------------------------ helpers

    private function bootstrapSchedule(): void
    {
        $sport = SportsSport::query()->where('key', 'football')->firstOrFail();
        app(SportsEngine::class)->importCompetitions($sport);
        app(SportsEngine::class)->syncDueSchedules();
    }

    /** The fake provider: what to answer for this request. */
    private function answer(Request $r): array
    {
        $path = trim((string) parse_url($r->url(), PHP_URL_PATH), '/');
        parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

        return match (true) {
            $path === 'leagues' => $this->api['leagues'] ?? [],
            $path === 'fixtures' && isset($q['live']) => $this->api['fixtures?live'] ?? [],
            $path === 'fixtures' && (isset($q['ids']) || isset($q['id'])) => $this->api['fixtures?ids'] ?? [],
            $path === 'fixtures' && isset($q['date']) => ($q['date'] === '2026-10-17') ? ($this->api['fixtures?date'] ?? []) : [],
            $path === 'standings' => $this->api['standings'] ?? [],
            default => [],
        };
    }

    private function league(int $id, string $name, string $country, string $code): array
    {
        return ['league' => ['id' => $id, 'name' => $name, 'type' => 'League', 'logo' => null], 'country' => ['name' => $country, 'code' => $code, 'flag' => null],
            'seasons' => [['year' => 2026, 'start' => '2026-08-01', 'end' => '2027-05-30', 'current' => true, 'coverage' => ['standings' => true, 'fixtures' => ['events' => true, 'lineups' => true, 'statistics_fixtures' => true]]]]];
    }

    private function fixture(int $id, int $league, array $home, array $away, string $date, string $status = 'NS', ?int $elapsed = null, array $goals = [null, null], ?array $events = null, bool $full = false): array
    {
        $f = [
            'fixture' => ['id' => $id, 'date' => $date, 'status' => ['short' => $status, 'long' => $status, 'elapsed' => $elapsed, 'extra' => null], 'venue' => ['name' => 'Kingdom Arena', 'city' => 'Riyadh'], 'referee' => null],
            'league' => ['id' => $league, 'name' => 'League '.$league, 'season' => 2026, 'round' => 'Regular Season - 8'],
            'teams' => ['home' => ['id' => $home[0], 'name' => $home[1], 'winner' => $status === 'FT' ? $goals[0] > $goals[1] : null], 'away' => ['id' => $away[0], 'name' => $away[1], 'winner' => $status === 'FT' ? $goals[1] > $goals[0] : null]],
            'goals' => ['home' => $goals[0], 'away' => $goals[1]],
            'score' => ['halftime' => ['home' => null, 'away' => null], 'fulltime' => ['home' => $status === 'FT' ? $goals[0] : null, 'away' => $status === 'FT' ? $goals[1] : null], 'extratime' => ['home' => null, 'away' => null], 'penalty' => ['home' => null, 'away' => null]],
        ];
        if ($events !== null) {
            $f['events'] = $events;
        }
        if ($full) {
            $f['statistics'] = [['team' => ['id' => $home[0]], 'statistics' => [['type' => 'Ball Possession', 'value' => '58%']]], ['team' => ['id' => $away[0]], 'statistics' => [['type' => 'Ball Possession', 'value' => '42%']]]];
            $f['lineups'] = [];
        }

        return $f;
    }

    private function standing(int $rank, array $team, int $points): array
    {
        return ['rank' => $rank, 'team' => ['id' => $team[0], 'name' => $team[1]], 'points' => $points, 'goalsDiff' => 3, 'group' => 'Pro League', 'form' => 'WWD',
            'description' => null, 'all' => ['played' => 4, 'win' => 3, 'draw' => 0, 'lose' => 1, 'goals' => ['for' => 8, 'against' => 5]], 'update' => '2026-10-17T00:00:00+00:00'];
    }

    private function requests(string $endpoint, ?string $param = null): int
    {
        return collect(Http::recorded())->map(fn ($p) => $p[0])->filter(function ($r) use ($endpoint, $param) {
            if (! str_contains($r->url(), 'api-sports.io/'.$endpoint)) {
                return false;
            }

            return $param === null || str_contains($r->url(), $param.'=');
        })->count();
    }

    /** @return list<array<string, mixed>> */
    private function pushes(): array
    {
        app(DeferredCallbackCollection::class)->invoke();

        return collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn ($r) => str_contains($r->url(), 'onesignal') && str_starts_with((string) ($r['data']['type'] ?? ''), 'sports'))
            ->map(fn ($r) => $r->data())->values()->all();
    }

    private function asAdmin(array $permissions): void
    {
        $admin = Admin::query()->firstOrCreate(['email' => 'a@example.com'], ['name' => 'A', 'password' => 'secret123', 'status' => 'active']);
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'admin_api');
        }
        $admin->givePermissionTo($permissions);
        Sanctum::actingAs($admin, [], 'admin_api');
    }

    private function as(User $user): static
    {
        Sanctum::actingAs($user, [], 'user_api');

        return $this;
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['X-Country' => 'SA'];
    }
}
