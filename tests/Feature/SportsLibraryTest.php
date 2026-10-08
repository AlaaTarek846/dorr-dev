<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Modules\Sports\Database\Seeders\SportsSeeder;
use Modules\Sports\Models\SportsCompetition;
use Modules\Sports\Models\SportsMatch;
use Modules\Sports\Models\SportsSetting;
use Modules\Sports\Models\SportsSport;
use Modules\Sports\Models\SportsTeam;
use Modules\Sports\Services\SportsEngine;
use Modules\Sports\Support\SportsMedia;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * DORR Sports, the deep pages (docs/sports-plan.md §10): whatever the provider has is read once,
 * kept for as long as the provider says it stays good, and shown from our copy — rounds and
 * leaders, squads, team statistics, players and coaches, the poster, predictions, head to head,
 * injuries, odds (information only, where allowed), search, and the logos and photos themselves.
 */
class SportsLibraryTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    /** What the fake provider answers, by "endpoint" or "endpoint?param". */
    private array $api = [];

    /** The provider's errors, by endpoint. */
    private array $errors = [];

    private SportsCompetition $league;

    protected function setUp(): void
    {
        parent::setUp();

        $flag = Flag::create(['code' => 'sa']);
        Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR', 'decimal_places' => 2])->id, 'status' => true]);
        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $code => $direction) {
            Language::create(['code' => $code, 'direction' => $direction, 'is_default_website' => $code === 'en', 'is_default_dashboard' => $code === 'en', 'stores_translation' => true, 'status' => true, 'flag_id' => $flag->id]);
        }
        $this->alice = User::create(['name' => 'Alice', 'phone' => '+966500000001', 'country_id' => Country::query()->value('id'), 'status' => 'active', 'phone_verified_at' => now()]);
        Sanctum::actingAs($this->alice, [], 'user_api');

        $this->seed(SportsSeeder::class);
        config(['services.api_sports.key' => 'test-key', 'services.api_sports.daily_limit' => 7500]);
        Http::fake([
            'media.api-sports.io/*' => Http::response('PNG-BYTES', 200, ['Content-Type' => 'image/png']),
            '*.api-sports.io/*' => fn (Request $r) => Http::response(['response' => $this->answer($r), 'errors' => $this->errors[$this->path($r)] ?? [], 'results' => 1], 200),
        ]);
        $this->travelTo(Carbon::parse('2026-10-17 15:00', 'UTC'));

        $sport = SportsSport::query()->where('key', 'football')->firstOrFail();
        $this->league = SportsCompetition::query()->create(['sport_id' => $sport->id, 'provider_id' => 307, 'name' => 'Pro League', 'type' => 'League', 'country_name' => 'Saudi-Arabia', 'season' => '2026', 'tier' => 'big']);
        $this->api['fixtures?season'] = [
            $this->fixture(1001, [2932, 'Al-Hilal'], [2939, 'Al-Nassr'], '2026-10-10T17:00:00+00:00', 'Regular Season - 7', 'FT', [2, 1]),
            $this->fixture(1002, [2932, 'Al-Hilal'], [2933, 'Al-Ittihad'], '2026-10-18T17:00:00+00:00', 'Regular Season - 8'),
            $this->fixture(1003, [2934, 'Al-Ahli'], [2939, 'Al-Nassr'], '2026-10-18T19:00:00+00:00', 'Regular Season - 8'),
        ];
    }

    public function test_a_competition_has_its_rounds_its_season_and_its_leaders_read_once(): void
    {
        $this->api['fixtures/rounds'] = [['round' => 'Regular Season - 7', 'dates' => ['2026-10-10']], ['round' => 'Regular Season - 8', 'dates' => ['2026-10-18']]];
        $page = $this->getJson("/api/mobile/v1/sports/competitions/{$this->league->id}/rounds", $this->headers())->assertOk();
        $page->assertJsonPath('data.current', 'Regular Season - 8')
            ->assertJsonCount(2, 'data.rounds')
            ->assertJsonPath('data.rounds.1.dates.0', '2026-10-18')
            ->assertJsonCount(2, 'data.matches');
        $this->getJson("/api/mobile/v1/sports/competitions/{$this->league->id}/rounds?round=Regular%20Season%20-%207", $this->headers())->assertOk()
            ->assertJsonPath('data.matches.0.home_score', 2);
        // The whole season was one request, the rounds another — and neither is asked twice today.
        $this->assertSame(1, $this->requests('fixtures', 'season'));
        $this->assertSame(1, $this->requests('fixtures/rounds'));

        $this->api['players/topassists'] = [$this->leader(874, 'Neymar', [2932, 'Al-Hilal'], ['goals' => ['total' => 3, 'assists' => 9]])];
        $this->getJson("/api/mobile/v1/sports/competitions/{$this->league->id}/leaders?type=assists", $this->headers())->assertOk()
            ->assertJsonPath('data.state', 'ok')
            ->assertJsonPath('data.rows.0.value', 9)
            ->assertJsonPath('data.rows.0.player.photo', url('/api/mobile/v1/sports/media/football/players/874.png'))
            ->assertJsonPath('data.rows.0.team.name', 'Al-Hilal');
        $this->getJson("/api/mobile/v1/sports/competitions/{$this->league->id}/leaders?type=assists", $this->headers())->assertOk();
        $this->assertSame(1, $this->requests('players/topassists'));

        // The plan refuses this season's red cards: "unavailable", and not asked again for a week.
        $this->errors['players/topredcards'] = ['plan' => 'Free plans do not have access to this season, try from 2022 to 2024.'];
        $this->getJson("/api/mobile/v1/sports/competitions/{$this->league->id}/leaders?type=red", $this->headers())->assertOk()->assertJsonPath('data.state', 'unavailable');
        $this->travel(2)->hours();
        $this->getJson("/api/mobile/v1/sports/competitions/{$this->league->id}/leaders?type=red", $this->headers())->assertOk()->assertJsonPath('data.state', 'unavailable');
        $this->assertSame(1, $this->requests('players/topredcards'));
    }

    public function test_after_a_plan_upgrade_what_the_old_plan_refused_is_asked_again_at_once(): void
    {
        $this->errors['players/topscorers'] = ['plan' => 'Free plans do not have access to this season, try from 2022 to 2024.'];
        $this->errors['standings'] = $this->errors['players/topscorers'];
        $this->getJson("/api/mobile/v1/sports/competitions/{$this->league->id}/leaders?type=goals", $this->headers())->assertOk()->assertJsonPath('data.state', 'unavailable');
        app(SportsEngine::class)->syncDueStandings();
        $this->assertTrue(app(SportsEngine::class)->refused('standings', $this->league->refresh()));

        // The new plan covers it.
        unset($this->errors['players/topscorers'], $this->errors['standings']);
        $this->api['players/topscorers'] = [$this->leader(874, 'C. Ronaldo', [2939, 'Al-Nassr'], ['goals' => ['total' => 12]])];
        $before = $this->requests('players/topscorers');
        $this->artisan('sports:plan-changed')->assertSuccessful();
        $this->travel(2)->minutes();

        $this->assertFalse(app(SportsEngine::class)->refused('standings', $this->league->refresh()));
        $this->assertNull($this->league->standings_synced_at);
        $this->getJson("/api/mobile/v1/sports/competitions/{$this->league->id}/leaders?type=goals", $this->headers())->assertOk()
            ->assertJsonPath('data.state', 'ok')->assertJsonPath('data.rows.0.value', 12);
        // One new request, the moment someone opens it.
        $this->assertSame($before + 1, $this->requests('players/topscorers'));
    }

    public function test_a_team_has_its_venue_coach_form_squad_statistics_and_transfers(): void
    {
        app(SportsEngine::class)->syncSeason($this->league->load('sport'));
        $hilal = SportsTeam::query()->where('provider_id', 2932)->firstOrFail();
        $this->api['teams'] = [['team' => ['id' => 2932, 'name' => 'Al-Hilal', 'code' => 'HIL', 'country' => 'Saudi-Arabia', 'founded' => 1957, 'logo' => $this->logo(2932)],
            'venue' => ['id' => 1460, 'name' => 'Kingdom Arena', 'city' => 'Riyadh', 'capacity' => 26000, 'surface' => 'grass', 'image' => 'https://media.api-sports.io/football/venues/1460.png']]];
        $this->api['coachs'] = [['id' => 2407, 'name' => 'S. Inzaghi', 'nationality' => 'Italy', 'age' => 50, 'photo' => 'https://media.api-sports.io/football/coachs/2407.png',
            'team' => ['id' => 2932, 'name' => 'Al-Hilal', 'logo' => $this->logo(2932)], 'career' => [['team' => ['id' => 2932, 'name' => 'Al-Hilal', 'logo' => $this->logo(2932)], 'start' => '2025-06-01', 'end' => null]]]];
        $this->api['fixtures?team'] = $this->api['fixtures?season'];

        $this->getJson("/api/mobile/v1/sports/teams/{$hilal->id}", $this->headers())->assertOk()
            ->assertJsonPath('data.founded', 1957)
            ->assertJsonPath('data.venue.name', 'Kingdom Arena')
            ->assertJsonPath('data.venue.image', url('/api/mobile/v1/sports/media/football/venues/1460.png'))
            ->assertJsonPath('data.coach.name', 'S. Inzaghi')
            ->assertJsonPath('data.form.0.result', 'W')
            ->assertJsonPath('data.next.0.away.name', 'Al-Ittihad');

        $this->api['players/squads'] = [['team' => ['id' => 2932], 'players' => [
            ['id' => 10, 'name' => 'Neymar', 'age' => 34, 'number' => 10, 'position' => 'Attacker', 'photo' => 'https://media.api-sports.io/football/players/10.png'],
            ['id' => 1, 'name' => 'Bono', 'age' => 35, 'number' => 37, 'position' => 'Goalkeeper', 'photo' => 'https://media.api-sports.io/football/players/1.png'],
        ]]];
        $this->getJson("/api/mobile/v1/sports/teams/{$hilal->id}/squad", $this->headers())->assertOk()
            ->assertJsonPath('data.data.0.position', 'Goalkeeper')->assertJsonPath('data.data.1.players.0.name', 'Neymar');

        $this->api['teams/statistics'] = ['league' => ['name' => 'Pro League', 'season' => 2026], 'form' => 'WWD', 'fixtures' => ['played' => ['total' => 3]],
            'goals' => ['for' => ['total' => ['total' => 7], 'minute' => ['0-15' => ['total' => 2, 'percentage' => '28.57%']]], 'against' => ['total' => ['total' => 2], 'minute' => []]],
            'clean_sheet' => ['total' => 1], 'lineups' => [['formation' => '4-3-3', 'played' => 3]], 'cards' => ['yellow' => [], 'red' => []]];
        $this->getJson("/api/mobile/v1/sports/teams/{$hilal->id}/statistics", $this->headers())->assertOk()
            ->assertJsonPath('data.state', 'ok')->assertJsonPath('data.data.goals.for.minute.0.total', 2)->assertJsonPath('data.competition.name', 'Pro League');

        // A transfer from a club we never saw: it becomes one of ours, so it opens.
        $this->api['transfers'] = [['player' => ['id' => 10, 'name' => 'Neymar'], 'transfers' => [['date' => '2023-08-15', 'type' => '€ 90M', 'teams' => [
            'in' => ['id' => 2932, 'name' => 'Al-Hilal', 'logo' => $this->logo(2932)], 'out' => ['id' => 85, 'name' => 'Paris Saint Germain', 'logo' => $this->logo(85)]]]]]];
        $this->getJson("/api/mobile/v1/sports/teams/{$hilal->id}/transfers", $this->headers())->assertOk()
            ->assertJsonPath('data.data.0.direction', 'in')->assertJsonPath('data.data.0.from.name', 'Paris Saint Germain');
        $this->assertNotNull(SportsTeam::query()->where('provider_id', 85)->first());
    }

    public function test_players_and_coaches_have_their_pages(): void
    {
        $this->api['players/profiles'] = [['player' => ['id' => 874, 'name' => 'C. Ronaldo', 'age' => 41, 'nationality' => 'Portugal', 'height' => '187 cm', 'position' => 'Attacker', 'number' => 7, 'photo' => 'https://media.api-sports.io/football/players/874.png']]];
        $this->api['players?id'] = [['player' => ['id' => 874, 'name' => 'C. Ronaldo', 'age' => 41], 'statistics' => [[
            'team' => ['id' => 2939, 'name' => 'Al-Nassr', 'logo' => $this->logo(2939)], 'league' => ['name' => 'Pro League', 'season' => 2026],
            'games' => ['appearences' => 8, 'minutes' => 700, 'rating' => '7.912', 'position' => 'Attacker'], 'goals' => ['total' => 9, 'assists' => 2], 'cards' => ['yellow' => 1, 'red' => 0],
        ]]]];
        $this->getJson('/api/mobile/v1/sports/players/874', $this->headers())->assertOk()
            ->assertJsonPath('data.name', 'C. Ronaldo')->assertJsonPath('data.seasons.0.goals', 9)->assertJsonPath('data.seasons.0.rating', 7.91)
            ->assertJsonPath('data.team.name', 'Al-Nassr')->assertJsonPath('data.stats_state', 'ok');

        $this->api['trophies'] = [['league' => 'UEFA Champions League', 'country' => 'Europe', 'season' => '2016/2017', 'place' => 'Winner']];
        $this->api['players/teams'] = [['team' => ['id' => 2939, 'name' => 'Al-Nassr', 'logo' => $this->logo(2939)], 'seasons' => [2026, 2025]]];
        $this->getJson('/api/mobile/v1/sports/players/874/career', $this->headers())->assertOk()
            ->assertJsonPath('data.trophies.data.0.place', 'Winner')->assertJsonPath('data.teams.data.0.seasons.0', 2026)
            ->assertJsonPath('data.sidelined.state', 'ok');

        $this->api['coachs'] = [['id' => 2407, 'name' => 'S. Inzaghi', 'photo' => 'https://media.api-sports.io/football/coachs/2407.png', 'career' => []]];
        $this->getJson('/api/mobile/v1/sports/coaches/2407', $this->headers())->assertOk()
            ->assertJsonPath('data.name', 'S. Inzaghi')->assertJsonPath('data.photo', url('/api/mobile/v1/sports/media/football/coachs/2407.png'))
            ->assertJsonPath('data.trophies.data.0.place', 'Winner');
    }

    public function test_a_match_has_its_poster_ratings_prediction_head_to_head_injuries_and_odds_only_where_allowed(): void
    {
        app(SportsEngine::class)->syncSeason($this->league->load('sport'));
        $past = SportsMatch::query()->where('provider_id', 1001)->firstOrFail();
        $sheet = $this->fixture(1001, [2932, 'Al-Hilal'], [2939, 'Al-Nassr'], '2026-10-10T17:00:00+00:00', 'Regular Season - 7', 'FT', [2, 1]);
        $sheet['fixture']['venue']['id'] = 1460;
        $sheet['lineups'] = [
            ['team' => ['id' => 2932], 'formation' => '4-3-3', 'coach' => ['id' => 2407, 'name' => 'S. Inzaghi'], 'startXI' => [['player' => ['id' => 10, 'name' => 'Neymar', 'number' => 10, 'pos' => 'F', 'grid' => '4:2']]], 'substitutes' => []],
            ['team' => ['id' => 2939], 'formation' => '4-4-2', 'coach' => ['id' => 99, 'name' => 'S. Pioli'], 'startXI' => [['player' => ['id' => 874, 'name' => 'C. Ronaldo', 'number' => 7, 'pos' => 'F', 'grid' => '4:1']]], 'substitutes' => []],
        ];
        $sheet['players'] = [
            ['team' => ['id' => 2932], 'players' => [['player' => ['id' => 10, 'name' => 'Neymar'], 'statistics' => [['games' => ['minutes' => 90, 'number' => 10, 'rating' => '8.4', 'captain' => true], 'goals' => ['total' => 2]]]]]],
            ['team' => ['id' => 2939], 'players' => [['player' => ['id' => 874, 'name' => 'C. Ronaldo'], 'statistics' => [['games' => ['minutes' => 90, 'number' => 7, 'rating' => '7.1', 'captain' => true], 'goals' => ['total' => 1]]]]]],
        ];
        $this->api['fixtures?id'] = [$sheet];
        $this->getJson("/api/mobile/v1/sports/matches/{$past->uuid}", $this->headers())->assertOk()
            ->assertJsonPath('data.lineups.home.start.0.rating', 8.4)
            ->assertJsonPath('data.lineups.home.start.0.captain', true)
            ->assertJsonPath('data.lineups.home.coach_photo', url('/api/mobile/v1/sports/media/football/coachs/2407.png'))
            ->assertJsonPath('data.players.home.0.name', 'Neymar')
            ->assertJsonPath('data.poster.venue.image', url('/api/mobile/v1/sports/media/football/venues/1460.png'))
            ->assertJsonPath('data.poster.home.captain.name', 'Neymar')
            ->assertJsonPath('data.poster.away.coach.name', 'S. Pioli');

        // The next match: last match's captains on the poster, and the provider's numbers.
        $next = SportsMatch::query()->where('provider_id', 1002)->firstOrFail();
        $this->api['predictions'] = [[
            'predictions' => ['winner' => ['id' => 2932, 'name' => 'Al-Hilal', 'comment' => 'Win or draw'], 'advice' => 'Double chance : Al-Hilal or draw', 'percent' => ['home' => '50%', 'draw' => '30%', 'away' => '20%'], 'goals' => ['home' => '-2.5', 'away' => '-1.5']],
            'comparison' => ['form' => ['home' => '60%', 'away' => '40%'], 'att' => ['home' => '55%', 'away' => '45%']],
            'teams' => ['home' => ['last_5' => ['form' => '80%']], 'away' => ['last_5' => ['form' => '40%']]],
            'h2h' => [$this->fixture(900, [2933, 'Al-Ittihad'], [2932, 'Al-Hilal'], '2025-03-01T17:00:00+00:00', 'Regular Season - 20', 'FT', [0, 3])],
        ]];
        $this->api['injuries'] = [['player' => ['id' => 55, 'name' => 'Benzema', 'type' => 'Missing Fixture', 'reason' => 'Knee Injury'], 'team' => ['id' => 2933, 'name' => 'Al-Ittihad', 'logo' => $this->logo(2933)]]];
        $this->api['odds'] = [['bookmakers' => [['id' => 8, 'name' => 'Bet365', 'bets' => [['id' => 1, 'name' => 'Match Winner', 'values' => [['value' => 'Home', 'odd' => '1.80'], ['value' => 'Draw', 'odd' => '3.40'], ['value' => 'Away', 'odd' => '4.20']]]]]]]];

        $insights = $this->getJson("/api/mobile/v1/sports/matches/{$next->uuid}/insights", $this->headers())->assertOk();
        $insights->assertJsonPath('data.prediction.data.percent.home', 50)
            ->assertJsonPath('data.prediction.data.comparison.0.type', 'form')
            ->assertJsonPath('data.h2h.data.summary.home', 1)
            ->assertJsonPath('data.h2h.data.matches.0.home_score', 0)
            ->assertJsonPath('data.injuries.data.0.side', 'away')
            ->assertJsonPath('data.injuries.data.0.reason', 'Knee Injury')
            ->assertJsonPath('data.odds', null)
            ->assertJsonPath('data.poster.home.captain.name', 'Neymar');
        $this->assertSame(0, $this->requests('fixtures/headtohead'));
        $this->assertSame(0, $this->requests('odds'));

        // The admin shows odds here: information only.
        SportsSetting::query()->firstOrFail()->update(['odds_countries' => [Country::query()->value('id')]]);
        $this->getJson("/api/mobile/v1/sports/matches/{$next->uuid}/insights", $this->headers())->assertOk()
            ->assertJsonPath('data.odds.data.bookmaker', 'Bet365')->assertJsonPath('data.odds.data.bets.0.values.0.odd', '1.80');
        $this->assertSame(1, $this->requests('predictions'));
    }

    public function test_search_finds_competitions_teams_and_players_and_photos_come_from_our_copy(): void
    {
        $this->api['teams?search'] = [['team' => ['id' => 541, 'name' => 'Real Madrid', 'country' => 'Spain', 'logo' => $this->logo(541)]]];
        $this->api['players/profiles?search'] = [['player' => ['id' => 874, 'name' => 'C. Ronaldo', 'nationality' => 'Portugal', 'position' => 'Attacker']]];
        $this->getJson('/api/mobile/v1/sports/search?q=real', $this->headers())->assertOk()
            ->assertJsonPath('data.teams.0.name', 'Real Madrid')->assertJsonPath('data.players.0.name', 'C. Ronaldo');
        $this->getJson('/api/mobile/v1/sports/search?q=pro', $this->headers())->assertOk()->assertJsonPath('data.competitions.0.name', 'Pro League');

        File::delete(SportsMedia::file('football/teams/541.png'));
        $this->get('/api/mobile/v1/sports/media/football/teams/541.png')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get('/api/mobile/v1/sports/media/football/teams/541.png')->assertOk();
        $this->assertSame(1, collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), 'media.api-sports.io/football/teams/541.png'))->count());
        $this->get('/api/mobile/v1/sports/media/../../.env')->assertNotFound();
        File::delete(SportsMedia::file('football/teams/541.png'));
    }

    // ------------------------------------------------------------------ the fake provider

    private function path(Request $r): string
    {
        return trim((string) parse_url($r->url(), PHP_URL_PATH), '/');
    }

    private function answer(Request $r): array
    {
        $path = $this->path($r);
        parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
        foreach (['season', 'team', 'id', 'search'] as $param) {
            if (isset($q[$param]) && array_key_exists("{$path}?{$param}", $this->api)) {
                return $this->api["{$path}?{$param}"];
            }
        }

        return $this->api[$path] ?? [];
    }

    private function fixture(int $id, array $home, array $away, string $date, string $round, string $status = 'NS', array $goals = [null, null]): array
    {
        return [
            'fixture' => ['id' => $id, 'date' => $date, 'referee' => 'M. Oliver, England', 'status' => ['short' => $status, 'long' => $status, 'elapsed' => $status === 'FT' ? 90 : null, 'extra' => null], 'venue' => ['id' => null, 'name' => 'Kingdom Arena', 'city' => 'Riyadh']],
            'league' => ['id' => 307, 'name' => 'Pro League', 'season' => 2026, 'round' => $round, 'logo' => 'https://media.api-sports.io/football/leagues/307.png'],
            'teams' => ['home' => ['id' => $home[0], 'name' => $home[1], 'logo' => $this->logo($home[0]), 'winner' => $status === 'FT' ? $goals[0] > $goals[1] : null], 'away' => ['id' => $away[0], 'name' => $away[1], 'logo' => $this->logo($away[0]), 'winner' => $status === 'FT' ? $goals[1] > $goals[0] : null]],
            'goals' => ['home' => $goals[0], 'away' => $goals[1]],
            'score' => ['halftime' => ['home' => null, 'away' => null], 'fulltime' => ['home' => $goals[0], 'away' => $goals[1]], 'extratime' => ['home' => null, 'away' => null], 'penalty' => ['home' => null, 'away' => null]],
        ];
    }

    private function leader(int $id, string $name, array $team, array $stats): array
    {
        return ['player' => ['id' => $id, 'name' => $name, 'photo' => "https://media.api-sports.io/football/players/{$id}.png"], 'statistics' => [['team' => ['id' => $team[0], 'name' => $team[1], 'logo' => $this->logo($team[0])], 'games' => ['appearences' => 8]] + $stats]];
    }

    private function logo(int $id): string
    {
        return "https://media.api-sports.io/football/teams/{$id}.png";
    }

    private function requests(string $endpoint, ?string $param = null): int
    {
        return collect(Http::recorded())->map(fn ($p) => $p[0])->filter(function ($r) use ($endpoint, $param) {
            if ($this->path($r) !== $endpoint || ! str_contains($r->url(), 'api-sports.io/')) {
                return false;
            }

            return $param === null || str_contains($r->url(), $param.'=');
        })->count();
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['X-Country' => 'SA'];
    }
}
