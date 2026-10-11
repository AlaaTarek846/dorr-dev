package com.dorr.app.network

import com.google.gson.JsonElement
import com.google.gson.JsonObject
import com.google.gson.annotations.SerializedName

/*
 * DORR Sports, the deep pages (docs/sports-plan.md §10): rounds and leaders, squads, team
 * statistics, transfers, players, coaches, a match's insights and poster, search. Every part the
 * provider may not have says its `state`: ok · pending (not read yet) · unavailable (the plan).
 */

/** One part of a page, with whether the provider had it. */
data class SpPart<T>(
    val state: String = "pending",
    @SerializedName("fetched_at") val fetchedAt: String? = null,
    val data: T? = null,
)

/** A player or a coach (the provider's id — opens their page). */
data class SpPersonDto(
    val id: Int? = null,
    val name: String? = null,
    val photo: String? = null,
    val nationality: String? = null,
    val age: Int? = null,
    val position: String? = null,
    val number: Int? = null,
)

// ------------------------------------------------------------------ competitions

data class SpRoundDto(val name: String? = null, val dates: List<String> = emptyList())

data class SpRoundsDto(
    val rounds: List<SpRoundDto> = emptyList(),
    val current: String? = null,
    val round: String? = null,
    val matches: List<SpMatchDto> = emptyList(),
)

data class SpLeaderDto(
    val rank: Int = 0,
    val player: SpPersonDto? = null,
    val team: SpTeamDto? = null,
    val value: Int = 0,
    val goals: Int = 0,
    val assists: Int = 0,
    val penalties: Int = 0,
    val appearances: Int? = null,
    val minutes: Int? = null,
    val rating: Double? = null,
)

data class SpLeadersDto(
    val type: String = "goals",
    val state: String = "pending",
    @SerializedName("fetched_at") val fetchedAt: String? = null,
    val rows: List<SpLeaderDto> = emptyList(),
)

data class SpSplitDto(
    val played: Int = 0,
    val win: Int = 0,
    val draw: Int = 0,
    val lose: Int = 0,
    @SerializedName("goals_for") val goalsFor: Int = 0,
    @SerializedName("goals_against") val goalsAgainst: Int = 0,
)

// ------------------------------------------------------------------ teams

data class SpVenueDto(
    val id: Int? = null,
    val name: String? = null,
    val address: String? = null,
    val city: String? = null,
    val capacity: Int? = null,
    val surface: String? = null,
    val image: String? = null,
)

data class SpFormDto(val id: String = "", val result: String = "D", val score: String? = null)

data class SpTeamTableDto(
    val competition: SpCompetitionDto? = null,
    val group: String? = null,
    val rank: Int = 0,
    val points: Int = 0,
    val played: Int = 0,
    @SerializedName("goal_diff") val goalDiff: Int = 0,
    val form: String? = null,
    val description: String? = null,
)

data class SpSquadLineDto(val position: String? = null, val players: List<SpPersonDto> = emptyList())

data class SpMinuteDto(val range: String = "", val total: Int = 0, val percent: String? = null)

data class SpGoalSideDto(
    val total: JsonObject? = null,
    val average: JsonObject? = null,
    val minute: List<SpMinuteDto> = emptyList(),
    @SerializedName("under_over") val underOver: JsonObject? = null,
)

data class SpTeamStatsDataDto(
    val form: String? = null,
    val fixtures: JsonObject? = null,
    val goals: Map<String, SpGoalSideDto>? = null,
    val biggest: JsonObject? = null,
    @SerializedName("clean_sheet") val cleanSheet: JsonObject? = null,
    @SerializedName("failed_to_score") val failedToScore: JsonObject? = null,
    val penalty: JsonObject? = null,
    val lineups: List<SpFormationDto> = emptyList(),
    val cards: Map<String, List<SpMinuteDto>>? = null,
)

data class SpFormationDto(val formation: String? = null, val played: Int = 0)

data class SpTeamStatsDto(
    val state: String = "pending",
    @SerializedName("fetched_at") val fetchedAt: String? = null,
    val data: SpTeamStatsDataDto? = null,
    val competition: SpCompetitionDto? = null,
    val competitions: List<SpCompetitionDto> = emptyList(),
)

data class SpTransferDto(
    val date: String? = null,
    val type: String? = null,
    val player: SpPersonDto? = null,
    /** in · out (a team's transfers) */
    val direction: String? = null,
    val from: SpTeamDto? = null,
    val to: SpTeamDto? = null,
)

// ------------------------------------------------------------------ players & coaches

data class SpPlayerSeasonDto(
    val team: SpTeamDto? = null,
    val competition: SpCompetitionDto? = null,
    val appearances: Int? = null,
    val lineups: Int? = null,
    val minutes: Int? = null,
    val rating: Double? = null,
    val captain: Boolean = false,
    val goals: Int? = null,
    val assists: Int? = null,
    val saves: Int? = null,
    val conceded: Int? = null,
    val shots: Int? = null,
    @SerializedName("shots_on") val shotsOn: Int? = null,
    val passes: Int? = null,
    @SerializedName("key_passes") val keyPasses: Int? = null,
    @SerializedName("pass_accuracy") val passAccuracy: JsonElement? = null,
    val tackles: Int? = null,
    val interceptions: Int? = null,
    @SerializedName("duels_won") val duelsWon: Int? = null,
    val duels: Int? = null,
    val dribbles: Int? = null,
    @SerializedName("dribbles_tried") val dribblesTried: Int? = null,
    @SerializedName("fouls_drawn") val foulsDrawn: Int? = null,
    val fouls: Int? = null,
    val yellow: Int? = null,
    val red: Int? = null,
    @SerializedName("penalties_scored") val penaltiesScored: Int? = null,
    @SerializedName("penalties_missed") val penaltiesMissed: Int? = null,
)

data class SpBirthDto(val date: String? = null, val place: String? = null, val country: String? = null)

data class SpPlayerDto(
    val id: Int = 0,
    val name: String? = null,
    val firstname: String? = null,
    val lastname: String? = null,
    val age: Int? = null,
    val birth: SpBirthDto? = null,
    val nationality: String? = null,
    val height: String? = null,
    val weight: String? = null,
    val number: Int? = null,
    val position: String? = null,
    val injured: Boolean = false,
    val photo: String? = null,
    val team: SpTeamDto? = null,
    val seasons: List<SpPlayerSeasonDto> = emptyList(),
    val season: String? = null,
    @SerializedName("stats_state") val statsState: String = "pending",
)

data class SpCareerTeamDto(val team: SpTeamDto? = null, val seasons: List<Int> = emptyList())

data class SpTrophyDto(val competition: String? = null, val country: String? = null, val season: String? = null, val place: String? = null)

data class SpSidelinedDto(val type: String? = null, val start: String? = null, val end: String? = null)

data class SpCareerDto(
    val teams: SpPart<List<SpCareerTeamDto>>? = null,
    val trophies: SpPart<List<SpTrophyDto>>? = null,
    val transfers: SpPart<List<SpTransferDto>>? = null,
    val sidelined: SpPart<List<SpSidelinedDto>>? = null,
)

data class SpCoachJobDto(val team: SpTeamDto? = null, val start: String? = null, val end: String? = null)

data class SpCoachDto(
    val id: Int = 0,
    val name: String? = null,
    val firstname: String? = null,
    val lastname: String? = null,
    val age: Int? = null,
    val nationality: String? = null,
    val birth: SpBirthDto? = null,
    val photo: String? = null,
    val team: SpTeamDto? = null,
    val career: List<SpCoachJobDto> = emptyList(),
    val trophies: SpPart<List<SpTrophyDto>>? = null,
)

// ------------------------------------------------------------------ a match's extras

data class SpPercentDto(val home: Double? = null, val draw: Double? = null, val away: Double? = null)

data class SpCompareDto(val type: String = "", val home: Double? = null, val away: Double? = null)

data class SpFormSideDto(
    val form: Double? = null,
    val att: Double? = null,
    val def: Double? = null,
    @SerializedName("goals_for") val goalsFor: Int? = null,
    @SerializedName("goals_against") val goalsAgainst: Int? = null,
    @SerializedName("league_form") val leagueForm: String? = null,
)

data class SpForecastDto(
    val winner: String? = null,
    @SerializedName("winner_comment") val winnerComment: String? = null,
    @SerializedName("win_or_draw") val winOrDraw: Boolean? = null,
    @SerializedName("under_over") val underOver: String? = null,
    val goals: Map<String, String?>? = null,
    val advice: String? = null,
    val percent: SpPercentDto? = null,
    val comparison: List<SpCompareDto> = emptyList(),
    val home: SpFormSideDto? = null,
    val away: SpFormSideDto? = null,
)

data class SpH2hSummaryDto(
    val home: Int = 0,
    val draw: Int = 0,
    val away: Int = 0,
    @SerializedName("home_goals") val homeGoals: Int = 0,
    @SerializedName("away_goals") val awayGoals: Int = 0,
    val played: Int = 0,
)

data class SpH2hMatchDto(
    val date: String? = null,
    val competition: SpCompetitionDto? = null,
    val home: SpTeamDto? = null,
    val away: SpTeamDto? = null,
    @SerializedName("home_score") val homeScore: Int? = null,
    @SerializedName("away_score") val awayScore: Int? = null,
)

data class SpH2hDto(val summary: SpH2hSummaryDto? = null, val matches: List<SpH2hMatchDto> = emptyList())

data class SpInjuryDto(val side: String = "home", val player: SpPersonDto? = null, val type: String? = null, val reason: String? = null)

data class SpOddValueDto(val value: String = "", val odd: String? = null)

data class SpBetDto(val key: String = "", val name: String? = null, val values: List<SpOddValueDto> = emptyList())

data class SpOddsDto(val bookmaker: String? = null, @SerializedName("updated_at") val updatedAt: String? = null, val bets: List<SpBetDto> = emptyList())

data class SpRefereeDto(val name: String? = null, val country: String? = null)

data class SpPosterSideDto(val coach: SpPersonDto? = null, val captain: SpPersonDto? = null)

data class SpPosterVenueDto(val name: String? = null, val city: String? = null, val image: String? = null, val capacity: Int? = null)

data class SpPosterDto(
    val venue: SpPosterVenueDto? = null,
    val referee: SpRefereeDto? = null,
    val home: SpPosterSideDto? = null,
    val away: SpPosterSideDto? = null,
)

data class SpInsightsDto(
    val prediction: SpPart<SpForecastDto>? = null,
    val h2h: SpPart<SpH2hDto>? = null,
    val injuries: SpPart<List<SpInjuryDto>>? = null,
    val odds: SpPart<SpOddsDto>? = null,
    val poster: SpPosterDto? = null,
)

/** One player's line on the match sheet. */
data class SpSheetPlayerDto(
    val id: Int? = null,
    val name: String? = null,
    val number: Int? = null,
    val pos: String? = null,
    val minutes: Int? = null,
    val rating: Double? = null,
    val captain: Boolean = false,
    val sub: Boolean = false,
    val goals: Int = 0,
    val assists: Int = 0,
    val saves: Int? = null,
    val shots: Int? = null,
    @SerializedName("shots_on") val shotsOn: Int? = null,
    val passes: Int? = null,
    @SerializedName("key_passes") val keyPasses: Int? = null,
    @SerializedName("pass_accuracy") val passAccuracy: JsonElement? = null,
    val tackles: Int? = null,
    @SerializedName("duels_won") val duelsWon: Int? = null,
    val dribbles: Int? = null,
    val fouls: Int? = null,
    val yellow: Int = 0,
    val red: Int = 0,
    val photo: String? = null,
)

/** The home-screen widgets' data; `signedOut` is the phone's own note. */
data class SpWidgetDto(
    val mine: List<SpMatchDto> = emptyList(),
    val live: List<SpMatchDto> = emptyList(),
    @SerializedName("updated_at") val updatedAt: String? = null,
    val signedOut: Boolean = false,
)

data class SpSearchDto(
    val competitions: List<SpCompetitionDto> = emptyList(),
    val teams: List<SpTeamDto> = emptyList(),
    val players: List<SpPersonDto> = emptyList(),
)
