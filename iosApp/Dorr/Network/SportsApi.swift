import Foundation

// androidApp `network/SportsApi.kt` + `SportsDeepDtos.kt` — DORR Sports. Everything comes from our server (the
// provider is synced there). `timezone` is where I am now. The DTOs are plain optional structs read through
// `ApiClient.snake` (snake_case → camelCase); Android's defaults live in the computed accessors the screens use.

struct SpPredictionDto: Codable, Equatable {
    var winner: String?
    var homeScore: Int?
    var awayScore: Int?
    var points: Int?
    var exact: Bool?
    var correctWinner: Bool?
    /// won · lost · void
    var result: String?
}

struct SpCrowdDto: Codable, Equatable {
    var count: Int?
    var home: Int?
    var draw: Int?
    var away: Int?
    var topScore: String?
}

struct SpMyRatingDto: Codable, Equatable {
    var fun: Int?
    var excitement: Int?
}

struct SpRatingDto: Codable, Equatable {
    var count: Int?
    var fun: Double?
    var excitement: Double?
    var mine: SpMyRatingDto?
}

struct SpMyPrizeDto: Codable, Equatable {
    var status: String?
    var amountMinor: Int?
    var currencyCode: String?
    var prizeType: String?
}

struct SpBoardRowDto: Codable, Equatable {
    var rank: Int?
    var name: String?
    var points: Int?
    var exact: Int?
    var me: Bool?
}

/// A prediction contest. `rule`: exact · winner · points. `prize_type`: wallet · coupon · badge.
struct SpContestDto: Codable, Equatable, Identifiable {
    var id: String
    var name: String?
    var terms: String?
    var scope: String?
    var rule: String?
    var status: String?
    var matchId: String?
    var match: String?
    var competition: String?
    var round: String?
    var endsAt: String?
    var prizeType: String?
    var prizeAmountMinor: Int?
    var prizePercent: Int?
    var currencyCode: String?
    var distribution: String?
    var maxWinners: Int?
    var prizesHere: Bool?
    var myPrize: SpMyPrizeDto?
    var leaderboard: [SpBoardRowDto]?
    var myPoints: Int?
    var matches: [SpMatchDto]?
}

struct SpPlayDto: Codable, Equatable {
    var enabled: Bool?
    var locked: Bool?
    var mine: SpPredictionDto?
    var crowd: SpCrowdDto?
    var contests: [SpContestDto]?
    var rating: SpRatingDto?
}

struct SpPrizeDto: Codable, Equatable {
    var contestId: String?
    var name: String?
    var prizeType: String?
    var amountMinor: Int?
    var currencyCode: String?
    var status: String?
    var points: Int?
    var paidAt: String?
}

struct SpSportDto: Codable, Equatable {
    var key: String
    var name: String?
    var emoji: String?
}

struct SpTeamDto: Codable, Equatable, Identifiable {
    var id: Int
    var name: String?
    var code: String?
    var logo: String?
    var national: Bool?
    var color: String?
    var sport: String?
    var country: String?
}

struct SpCompetitionDto: Codable, Equatable, Identifiable {
    var id: Int
    var name: String?
    var logo: String?
    var country: String?
    var flag: String?
    var tier: String?
    var sport: String?
    var type: String?
    var scope: String?
    var season: String?
}

struct SpPeriodDto: Codable, Equatable {
    var label: String?
    var home: Int?
    var away: Int?
}

struct SpEventDto: Codable, Equatable {
    var minute: Int?
    var extra: Int?
    var side: String?
    /// goal · own_goal · penalty · missed_penalty · yellow · second_yellow · red · sub · var · other
    var type: String?
    var player: String?
    var playerId: Int?
    var assist: String?
    var assistId: Int?
    var detail: String?

    var kind: String { type ?? "other" }
}

struct SpStatDto: Codable, Equatable {
    var type: String?
    var home: JSONAny?
    var away: JSONAny?
}

struct SpLineupPlayerDto: Codable, Equatable {
    var id: Int?
    var name: String?
    var number: Int?
    var pos: String?
    var grid: String?
    var photo: String?
    var rating: Double?
    var captain: Bool?
    var goals: Int?
    var yellow: Int?
    var red: Int?
}

struct SpLineupDto: Codable, Equatable {
    var formation: String?
    var coach: String?
    var coachId: Int?
    var coachPhoto: String?
    var start: [SpLineupPlayerDto]?
    var subs: [SpLineupPlayerDto]?
    var colors: [String: String?]?
}

struct SpFollowingDto: Codable, Equatable {
    var home: Bool?
    var away: Bool?
}

/// A match in any sport. `status`: scheduled · live · break · finished · postponed · cancelled · suspended.
/// `minute` is as of `synced_at` — the phone keeps it ticking until the next update.
struct SpMatchDto: Codable, Equatable, Identifiable {
    var id: String
    var sport: String?
    var competition: SpCompetitionDto?
    var round: String?
    var home: SpTeamDto?
    var away: SpTeamDto?
    var startsAt: String?
    var localDate: String?
    var localTime: String?
    var status: String?
    var statusCode: String?
    var minute: Int?
    var minuteExtra: Int?
    var homeScore: Int?
    var awayScore: Int?
    var periods: [SpPeriodDto]?
    var winner: String?
    var version: Int?
    var syncedAt: String?
    /// Races (F1): the Grand Prix; fights (MMA) use home / away for the fighters.
    var title: String?
    var race: SpRaceDto?
    var fight: SpFightDto?
    var results: [SpRaceResultDto]?
    var leader: String?
    var venue: String?
    var city: String?
    var referee: String?
    var statusLabel: String?
    var events: [SpEventDto]?
    var statistics: [SpStatDto]?
    var lineups: [String: SpLineupDto]?
    var players: [String: [SpSheetPlayerDto]]?
    var poster: SpPosterDto?
    var following: SpFollowingDto?
    var channel: String?

    var state: String { status ?? "scheduled" }
    var started: Bool { ["live", "break", "finished"].contains(state) }
    var allPeriods: [SpPeriodDto] { periods ?? [] }
}

struct SpRaceDto: Codable, Equatable {
    var type: String?
    var laps: Int?
    var lap: Int?
    var circuit: String?
    var circuitImage: String?
    var country: String?
    var distance: String?
    var fastestLap: String?
}

struct SpRaceResultDto: Codable, Equatable {
    var position: Int?
    var driver: String?
    var abbr: String?
    var number: Int?
    var image: String?
    var team: String?
    var teamLogo: String?
    var time: String?
    var gap: String?
    var laps: Int?
    var grid: String?
    var pits: Int?
}

struct SpFightDto: Codable, Equatable {
    var category: String?
    var main: Bool?
    var wonBy: String?
    var round: Int?
    var time: String?
}

struct SpCompetitionGroupDto: Codable, Equatable {
    var competition: SpCompetitionDto?
    var followed: Bool?
    var matches: [SpMatchDto]?
}

struct SpFollowingIdsDto: Codable, Equatable {
    var teams: [Int]?
    var competitions: [Int]?
}

struct SpPrefsDto: Codable, Equatable {
    var noSpoilers: Bool?
    /// off · calm · normal · festive
    var celebration: String?
    var sounds: Bool?
    var vibrate: Bool?
    var reminderMinutes: Int?
    var goalsInQuiet: Bool?

    var hideScores: Bool { noSpoilers ?? false }
    var celebrationLevel: String { celebration ?? "normal" }
}

struct SpHomeDto: Codable, Equatable {
    var date: String?
    var sports: [SpSportDto]?
    var liveCount: Int?
    var mine: [SpMatchDto]?
    var competitions: [SpCompetitionGroupDto]?
    var following: SpFollowingIdsDto?
    var preferences: SpPrefsDto?
}

struct SpSplitDto: Codable, Equatable {
    var played: Int?
    var win: Int?
    var draw: Int?
    var lose: Int?
    var goalsFor: Int?
    var goalsAgainst: Int?
}

struct SpStandingRowDto: Codable, Equatable {
    var rank: Int?
    var team: SpTeamDto?
    var points: Int?
    var played: Int?
    var win: Int?
    var draw: Int?
    var lose: Int?
    var goalsFor: Int?
    var goalsAgainst: Int?
    var goalDiff: Int?
    var form: String?
    var description: String?
    /// up · down · same
    var trend: String?
    var home: SpSplitDto?
    var away: SpSplitDto?
}

struct SpStandingGroupDto: Codable, Equatable {
    var group: String?
    var rows: [SpStandingRowDto]?
}

struct SpScorerDto: Codable, Equatable {
    var rank: Int?
    var player: String?
    var photo: String?
    var team: String?
    var teamLogo: String?
    var goals: Int?
    var assists: Int?
}

struct SpCompTeamDto: Codable, Equatable, Identifiable {
    var id: Int
    var name: String?
    var logo: String?
    var color: String?
    var country: String?
    var following: Bool?
}

struct SpCompetitionPageDto: Codable, Equatable {
    var id: Int?
    var name: String?
    var logo: String?
    var country: String?
    var flag: String?
    var sport: String?
    var season: String?
    var standings: [SpStandingGroupDto]?
    var standingsUpdatedAt: String?
    var topScorers: [SpScorerDto]?
    /// ok · pending (not read yet) · unavailable (the provider has none for this season)
    var standingsState: String?
    var scorersState: String?
    var teams: [SpCompTeamDto]?
    var live: [SpMatchDto]?
    var next: [SpMatchDto]?
    var last: [SpMatchDto]?
    var following: Bool?
}

struct SpTeamFollowDto: Codable, Equatable {
    var id: Int?
    /// The keys come camel-cased by the snake decoder ("red_card" → "redCard").
    var alerts: [String: Bool]?
    var noSpoilers: Bool?
}

struct SpTeamPageDto: Codable, Equatable {
    var id: Int?
    var name: String?
    var logo: String?
    var color: String?
    var national: Bool?
    var sport: String?
    var country: String?
    var code: String?
    var founded: Int?
    var venue: SpVenueDto?
    var coach: SpPersonDto?
    var form: [SpFormDto]?
    var tables: [SpTeamTableDto]?
    var live: [SpMatchDto]?
    var next: [SpMatchDto]?
    var last: [SpMatchDto]?
    var follow: SpTeamFollowDto?
}

struct SpFollowTargetDto: Codable, Equatable {
    var id: Int
    var name: String?
    var logo: String?
    var color: String?
    var country: String?
    var flag: String?
}

struct SpFollowDto: Codable, Equatable, Identifiable {
    var id: Int
    var kind: String?
    var sport: String?
    var target: SpFollowTargetDto?
    var alerts: [String: Bool]?
    var noSpoilers: Bool?
}

// MARK: - The deep pages

/// One part of a page, with whether the provider had it: ok · pending (not read yet) · unavailable.
struct SpPart<T: Codable & Equatable>: Codable, Equatable {
    var state: String?
    var fetchedAt: String?
    var data: T?
}

/// A player or a coach (the provider's id — opens their page).
struct SpPersonDto: Codable, Equatable {
    var id: Int?
    var name: String?
    var photo: String?
    var nationality: String?
    var age: Int?
    var position: String?
    var number: Int?
}

struct SpRoundDto: Codable, Equatable {
    var name: String?
    var dates: [String]?
}

struct SpRoundsDto: Codable, Equatable {
    var rounds: [SpRoundDto]?
    var current: String?
    var round: String?
    var matches: [SpMatchDto]?
}

struct SpLeaderDto: Codable, Equatable {
    var rank: Int?
    var player: SpPersonDto?
    var team: SpTeamDto?
    var value: Int?
    var goals: Int?
    var assists: Int?
    var penalties: Int?
    var appearances: Int?
    var minutes: Int?
    var rating: Double?
}

struct SpLeadersDto: Codable, Equatable {
    var type: String?
    var state: String?
    var fetchedAt: String?
    var rows: [SpLeaderDto]?
}

struct SpVenueDto: Codable, Equatable {
    var id: Int?
    var name: String?
    var address: String?
    var city: String?
    var capacity: Int?
    var surface: String?
    var image: String?
}

struct SpFormDto: Codable, Equatable {
    var id: String?
    var result: String?
    var score: String?
}

struct SpTeamTableDto: Codable, Equatable {
    var competition: SpCompetitionDto?
    var group: String?
    var rank: Int?
    var points: Int?
    var played: Int?
    var goalDiff: Int?
    var form: String?
    var description: String?
}

struct SpSquadLineDto: Codable, Equatable {
    var position: String?
    var players: [SpPersonDto]?
}

struct SpMinuteDto: Codable, Equatable {
    var range: String?
    var total: Int?
    var percent: String?
}

struct SpGoalSideDto: Codable, Equatable {
    var total: JSONAny?
    var average: JSONAny?
    var minute: [SpMinuteDto]?
    var underOver: JSONAny?
}

struct SpFormationDto: Codable, Equatable {
    var formation: String?
    var played: Int?
}

struct SpTeamStatsDataDto: Codable, Equatable {
    var form: String?
    var fixtures: JSONAny?
    var goals: [String: SpGoalSideDto]?
    var biggest: JSONAny?
    var cleanSheet: JSONAny?
    var failedToScore: JSONAny?
    var penalty: JSONAny?
    var lineups: [SpFormationDto]?
    var cards: [String: [SpMinuteDto]]?
}

struct SpTeamStatsDto: Codable, Equatable {
    var state: String?
    var fetchedAt: String?
    var data: SpTeamStatsDataDto?
    var competition: SpCompetitionDto?
    var competitions: [SpCompetitionDto]?
}

struct SpTransferDto: Codable, Equatable {
    var date: String?
    var type: String?
    var player: SpPersonDto?
    /// in · out (a team's transfers)
    var direction: String?
    var from: SpTeamDto?
    var to: SpTeamDto?
}

struct SpPlayerSeasonDto: Codable, Equatable {
    var team: SpTeamDto?
    var competition: SpCompetitionDto?
    var appearances: Int?
    var lineups: Int?
    var minutes: Int?
    var rating: Double?
    var captain: Bool?
    var goals: Int?
    var assists: Int?
    var saves: Int?
    var conceded: Int?
    var shots: Int?
    var shotsOn: Int?
    var passes: Int?
    var keyPasses: Int?
    var passAccuracy: JSONAny?
    var tackles: Int?
    var interceptions: Int?
    var duelsWon: Int?
    var duels: Int?
    var dribbles: Int?
    var dribblesTried: Int?
    var foulsDrawn: Int?
    var fouls: Int?
    var yellow: Int?
    var red: Int?
    var penaltiesScored: Int?
    var penaltiesMissed: Int?
}

struct SpBirthDto: Codable, Equatable {
    var date: String?
    var place: String?
    var country: String?
}

struct SpPlayerDto: Codable, Equatable {
    var id: Int?
    var name: String?
    var firstname: String?
    var lastname: String?
    var age: Int?
    var birth: SpBirthDto?
    var nationality: String?
    var height: String?
    var weight: String?
    var number: Int?
    var position: String?
    var injured: Bool?
    var photo: String?
    var team: SpTeamDto?
    var seasons: [SpPlayerSeasonDto]?
    var season: String?
    var statsState: String?
}

struct SpCareerTeamDto: Codable, Equatable {
    var team: SpTeamDto?
    var seasons: [Int]?
}

struct SpTrophyDto: Codable, Equatable {
    var competition: String?
    var country: String?
    var season: String?
    var place: String?
}

struct SpSidelinedDto: Codable, Equatable {
    var type: String?
    var start: String?
    var end: String?
}

struct SpCareerDto: Codable, Equatable {
    var teams: SpPart<[SpCareerTeamDto]>?
    var trophies: SpPart<[SpTrophyDto]>?
    var transfers: SpPart<[SpTransferDto]>?
    var sidelined: SpPart<[SpSidelinedDto]>?
}

struct SpCoachJobDto: Codable, Equatable {
    var team: SpTeamDto?
    var start: String?
    var end: String?
}

struct SpCoachDto: Codable, Equatable {
    var id: Int?
    var name: String?
    var firstname: String?
    var lastname: String?
    var age: Int?
    var nationality: String?
    var birth: SpBirthDto?
    var photo: String?
    var team: SpTeamDto?
    var career: [SpCoachJobDto]?
    var trophies: SpPart<[SpTrophyDto]>?
}

struct SpPercentDto: Codable, Equatable {
    var home: Double?
    var draw: Double?
    var away: Double?
}

struct SpCompareDto: Codable, Equatable {
    var type: String?
    var home: Double?
    var away: Double?
}

struct SpFormSideDto: Codable, Equatable {
    var form: Double?
    var att: Double?
    var def: Double?
    var goalsFor: Int?
    var goalsAgainst: Int?
    var leagueForm: String?
}

struct SpForecastDto: Codable, Equatable {
    var winner: String?
    var winnerComment: String?
    var winOrDraw: Bool?
    var underOver: String?
    var goals: [String: String?]?
    var advice: String?
    var percent: SpPercentDto?
    var comparison: [SpCompareDto]?
    var home: SpFormSideDto?
    var away: SpFormSideDto?
}

struct SpH2hSummaryDto: Codable, Equatable {
    var home: Int?
    var draw: Int?
    var away: Int?
    var homeGoals: Int?
    var awayGoals: Int?
    var played: Int?
}

struct SpH2hMatchDto: Codable, Equatable {
    var date: String?
    var competition: SpCompetitionDto?
    var home: SpTeamDto?
    var away: SpTeamDto?
    var homeScore: Int?
    var awayScore: Int?
}

struct SpH2hDto: Codable, Equatable {
    var summary: SpH2hSummaryDto?
    var matches: [SpH2hMatchDto]?
}

struct SpInjuryDto: Codable, Equatable {
    var side: String?
    var player: SpPersonDto?
    var type: String?
    var reason: String?
}

struct SpOddValueDto: Codable, Equatable {
    var value: String?
    var odd: String?
}

struct SpBetDto: Codable, Equatable {
    var key: String?
    var name: String?
    var values: [SpOddValueDto]?
}

struct SpOddsDto: Codable, Equatable {
    var bookmaker: String?
    var updatedAt: String?
    var bets: [SpBetDto]?
}

struct SpRefereeDto: Codable, Equatable {
    var name: String?
    var country: String?
}

struct SpPosterSideDto: Codable, Equatable {
    var coach: SpPersonDto?
    var captain: SpPersonDto?
}

struct SpPosterVenueDto: Codable, Equatable {
    var name: String?
    var city: String?
    var image: String?
    var capacity: Int?
}

struct SpPosterDto: Codable, Equatable {
    var venue: SpPosterVenueDto?
    var referee: SpRefereeDto?
    var home: SpPosterSideDto?
    var away: SpPosterSideDto?
}

struct SpInsightsDto: Codable, Equatable {
    var prediction: SpPart<SpForecastDto>?
    var h2h: SpPart<SpH2hDto>?
    var injuries: SpPart<[SpInjuryDto]>?
    var odds: SpPart<SpOddsDto>?
    var poster: SpPosterDto?
}

/// One player's line on the match sheet.
struct SpSheetPlayerDto: Codable, Equatable {
    var id: Int?
    var name: String?
    var number: Int?
    var pos: String?
    var minutes: Int?
    var rating: Double?
    var captain: Bool?
    var sub: Bool?
    var goals: Int?
    var assists: Int?
    var saves: Int?
    var shots: Int?
    var shotsOn: Int?
    var passes: Int?
    var keyPasses: Int?
    var passAccuracy: JSONAny?
    var tackles: Int?
    var duelsWon: Int?
    var dribbles: Int?
    var fouls: Int?
    var yellow: Int?
    var red: Int?
    var photo: String?
}

struct SpSearchDto: Codable, Equatable {
    var competitions: [SpCompetitionDto]?
    var teams: [SpTeamDto]?
    var players: [SpPersonDto]?
}

enum SportsApi {
    static func home(timezone: String, date: String? = nil, sport: String? = nil) async throws -> ApiEnvelope<SpHomeDto> {
        try await ApiClient.snake("mobile/v1/sports/home", query: ["timezone": timezone, "date": date, "sport": sport])
    }

    static func matches(timezone: String, date: String? = nil, sport: String? = nil, competitionId: Int? = nil, teamId: Int? = nil,
                        live: Int? = nil, mine: Int? = nil) async throws -> ApiEnvelope<[SpMatchDto]> {
        try await ApiClient.snake("mobile/v1/sports/matches", query: [
            "timezone": timezone, "date": date, "sport": sport, "competition_id": competitionId.map(String.init),
            "team_id": teamId.map(String.init), "live": live.map(String.init), "mine": mine.map(String.init),
        ])
    }

    static func match(_ id: String, timezone: String) async throws -> ApiEnvelope<SpMatchDto> {
        try await ApiClient.snake("mobile/v1/sports/matches/\(id)", query: ["timezone": timezone])
    }

    static func competitions(sport: String? = nil, q: String? = nil) async throws -> ApiEnvelope<[SpCompetitionDto]> {
        try await ApiClient.snake("mobile/v1/sports/competitions", query: ["sport": sport, "q": q])
    }

    static func competition(_ id: Int, timezone: String) async throws -> ApiEnvelope<SpCompetitionPageDto> {
        try await ApiClient.snake("mobile/v1/sports/competitions/\(id)", query: ["timezone": timezone])
    }

    static func teams(q: String, sport: String? = nil) async throws -> ApiEnvelope<[SpTeamDto]> {
        try await ApiClient.snake("mobile/v1/sports/teams", query: ["q": q, "sport": sport])
    }

    static func team(_ id: Int, timezone: String) async throws -> ApiEnvelope<SpTeamPageDto> {
        try await ApiClient.snake("mobile/v1/sports/teams/\(id)", query: ["timezone": timezone])
    }

    static func follows() async throws -> ApiEnvelope<[SpFollowDto]> {
        try await ApiClient.snake("mobile/v1/sports/follows")
    }

    /// `{kind: team | competition, target_id, alerts?{…}, no_spoilers?}` — adds or updates.
    static func follow(_ body: [String: JSONValue]) async throws -> ApiEnvelope<[SpFollowDto]> {
        try await ApiClient.snake("mobile/v1/sports/follows", method: "POST", body: body)
    }

    static func unfollow(_ id: Int) async throws -> ApiEnvelope<[SpFollowDto]> {
        try await ApiClient.snake("mobile/v1/sports/follows/\(id)", method: "DELETE")
    }

    static func preferences() async throws -> ApiEnvelope<SpPrefsDto> {
        try await ApiClient.snake("mobile/v1/sports/preferences")
    }

    static func savePreferences(_ body: [String: JSONValue]) async throws -> ApiEnvelope<SpPrefsDto> {
        try await ApiClient.snake("mobile/v1/sports/preferences", method: "PUT", body: body)
    }

    static func play(_ id: String) async throws -> ApiEnvelope<SpPlayDto> {
        try await ApiClient.snake("mobile/v1/sports/matches/\(id)/play")
    }

    /// `{home_score, away_score}` or `{winner: home | draw | away}` — until kick-off.
    static func predict(_ id: String, _ body: [String: JSONValue]) async throws -> ApiEnvelope<SpPlayDto> {
        try await ApiClient.snake("mobile/v1/sports/matches/\(id)/prediction", method: "POST", body: body)
    }

    static func rate(_ id: String, _ body: [String: JSONValue]) async throws -> ApiEnvelope<SpPlayDto> {
        try await ApiClient.snake("mobile/v1/sports/matches/\(id)/rating", method: "POST", body: body)
    }

    static func share(_ id: String, _ body: [String: JSONValue]) async throws -> ApiEnvelope<JSONAny?> {
        try await ApiClient.snake("mobile/v1/sports/matches/\(id)/share", method: "POST", body: body)
    }

    static func room(_ id: String, _ body: [String: JSONValue]) async throws -> ApiEnvelope<JSONAny?> {
        try await ApiClient.snake("mobile/v1/sports/matches/\(id)/room", method: "POST", body: body)
    }

    static func contests() async throws -> ApiEnvelope<[SpContestDto]> {
        try await ApiClient.snake("mobile/v1/sports/contests")
    }

    static func contest(_ id: String, timezone: String) async throws -> ApiEnvelope<SpContestDto> {
        try await ApiClient.snake("mobile/v1/sports/contests/\(id)", query: ["timezone": timezone])
    }

    static func prizes() async throws -> ApiEnvelope<[SpPrizeDto]> {
        try await ApiClient.snake("mobile/v1/sports/prizes")
    }

    static func rounds(_ id: Int, timezone: String, round: String? = nil) async throws -> ApiEnvelope<SpRoundsDto> {
        try await ApiClient.snake("mobile/v1/sports/competitions/\(id)/rounds", query: ["timezone": timezone, "round": round])
    }

    /// `type`: goals · assists · yellow · red
    static func leaders(_ id: Int, type: String) async throws -> ApiEnvelope<SpLeadersDto> {
        try await ApiClient.snake("mobile/v1/sports/competitions/\(id)/leaders", query: ["type": type])
    }

    static func squad(_ id: Int) async throws -> ApiEnvelope<SpPart<[SpSquadLineDto]>> {
        try await ApiClient.snake("mobile/v1/sports/teams/\(id)/squad")
    }

    static func teamStatistics(_ id: Int, competition: Int? = nil) async throws -> ApiEnvelope<SpTeamStatsDto> {
        try await ApiClient.snake("mobile/v1/sports/teams/\(id)/statistics", query: ["competition": competition.map(String.init)])
    }

    static func transfers(_ id: Int) async throws -> ApiEnvelope<SpPart<[SpTransferDto]>> {
        try await ApiClient.snake("mobile/v1/sports/teams/\(id)/transfers")
    }

    static func player(_ id: Int, season: String? = nil) async throws -> ApiEnvelope<SpPlayerDto> {
        try await ApiClient.snake("mobile/v1/sports/players/\(id)", query: ["season": season])
    }

    static func playerCareer(_ id: Int) async throws -> ApiEnvelope<SpCareerDto> {
        try await ApiClient.snake("mobile/v1/sports/players/\(id)/career")
    }

    static func coach(_ id: Int) async throws -> ApiEnvelope<SpCoachDto> {
        try await ApiClient.snake("mobile/v1/sports/coaches/\(id)")
    }

    static func insights(_ id: String) async throws -> ApiEnvelope<SpInsightsDto> {
        try await ApiClient.snake("mobile/v1/sports/matches/\(id)/insights")
    }

    static func search(_ q: String) async throws -> ApiEnvelope<SpSearchDto> {
        try await ApiClient.snake("mobile/v1/sports/search", query: ["q": q])
    }
}
