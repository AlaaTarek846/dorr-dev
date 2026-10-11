package com.dorr.app.network

import com.google.gson.JsonObject
import com.google.gson.annotations.SerializedName
import retrofit2.http.Body
import retrofit2.http.DELETE
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.POST
import retrofit2.http.PUT
import retrofit2.http.Path
import retrofit2.http.Query

/**
 * DORR Sports (spec 183–200). Everything comes from our server (the provider is synced there);
 * live changes arrive on Pusher: `sports.match.{id}` / `sports.live` (`sports.match.updated`) and
 * my private channel (`sports.alert`). `timezone` is where I am now.
 */
interface SportsApi {
    @GET("mobile/v1/sports/home")
    suspend fun home(@Header("Authorization") auth: String, @Query("timezone") timezone: String, @Query("date") date: String? = null, @Query("sport") sport: String? = null): ApiEnvelope<SpHomeDto>

    @GET("mobile/v1/sports/matches")
    suspend fun matches(
        @Header("Authorization") auth: String,
        @Query("timezone") timezone: String,
        @Query("date") date: String? = null,
        @Query("sport") sport: String? = null,
        @Query("competition_id") competitionId: Int? = null,
        @Query("team_id") teamId: Int? = null,
        @Query("live") live: Int? = null,
        @Query("mine") mine: Int? = null,
    ): ApiEnvelope<List<SpMatchDto>>

    @GET("mobile/v1/sports/matches/{id}")
    suspend fun match(@Header("Authorization") auth: String, @Path("id") id: String, @Query("timezone") timezone: String): ApiEnvelope<SpMatchDto>

    @GET("mobile/v1/sports/competitions")
    suspend fun competitions(@Header("Authorization") auth: String, @Query("sport") sport: String? = null, @Query("q") q: String? = null): ApiEnvelope<List<SpCompetitionDto>>

    @GET("mobile/v1/sports/competitions/{id}")
    suspend fun competition(@Header("Authorization") auth: String, @Path("id") id: Int, @Query("timezone") timezone: String): ApiEnvelope<SpCompetitionPageDto>

    @GET("mobile/v1/sports/teams")
    suspend fun teams(@Header("Authorization") auth: String, @Query("q") q: String, @Query("sport") sport: String? = null): ApiEnvelope<List<SpTeamDto>>

    @GET("mobile/v1/sports/teams/{id}")
    suspend fun team(@Header("Authorization") auth: String, @Path("id") id: Int, @Query("timezone") timezone: String): ApiEnvelope<SpTeamPageDto>

    @GET("mobile/v1/sports/follows")
    suspend fun follows(@Header("Authorization") auth: String): ApiEnvelope<List<SpFollowDto>>

    /** `{kind: team | competition, target_id, alerts?{…}, no_spoilers?}` — adds or updates. */
    @POST("mobile/v1/sports/follows")
    suspend fun follow(@Header("Authorization") auth: String, @Body body: JsonObject): ApiEnvelope<List<SpFollowDto>>

    @DELETE("mobile/v1/sports/follows/{id}")
    suspend fun unfollow(@Header("Authorization") auth: String, @Path("id") id: Int): ApiEnvelope<List<SpFollowDto>>

    @GET("mobile/v1/sports/preferences")
    suspend fun preferences(@Header("Authorization") auth: String): ApiEnvelope<SpPrefsDto>

    @PUT("mobile/v1/sports/preferences")
    suspend fun savePreferences(@Header("Authorization") auth: String, @Body body: JsonObject): ApiEnvelope<SpPrefsDto>

    // ------------------------------------------------------------------ predictions & contests (196, 199)

    @GET("mobile/v1/sports/matches/{id}/play")
    suspend fun play(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<SpPlayDto>

    /** `{home_score, away_score}` or `{winner: home | draw | away}` — until kick-off. */
    @POST("mobile/v1/sports/matches/{id}/prediction")
    suspend fun predict(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: JsonObject): ApiEnvelope<SpPlayDto>

    @POST("mobile/v1/sports/matches/{id}/rating")
    suspend fun rate(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: JsonObject): ApiEnvelope<SpPlayDto>

    @POST("mobile/v1/sports/matches/{id}/share")
    suspend fun share(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: JsonObject): ApiEnvelope<JsonObject>

    @POST("mobile/v1/sports/matches/{id}/room")
    suspend fun room(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: JsonObject): ApiEnvelope<JsonObject>

    @GET("mobile/v1/sports/contests")
    suspend fun contests(@Header("Authorization") auth: String): ApiEnvelope<List<SpContestDto>>

    @GET("mobile/v1/sports/contests/{id}")
    suspend fun contest(@Header("Authorization") auth: String, @Path("id") id: String, @Query("timezone") timezone: String): ApiEnvelope<SpContestDto>

    @GET("mobile/v1/sports/prizes")
    suspend fun prizes(@Header("Authorization") auth: String): ApiEnvelope<List<SpPrizeDto>>

    // ------------------------------------------------------------------ the deep pages (§10)

    @GET("mobile/v1/sports/competitions/{id}/rounds")
    suspend fun rounds(@Header("Authorization") auth: String, @Path("id") id: Int, @Query("timezone") timezone: String, @Query("round") round: String? = null): ApiEnvelope<SpRoundsDto>

    /** `type`: goals · assists · yellow · red */
    @GET("mobile/v1/sports/competitions/{id}/leaders")
    suspend fun leaders(@Header("Authorization") auth: String, @Path("id") id: Int, @Query("type") type: String): ApiEnvelope<SpLeadersDto>

    @GET("mobile/v1/sports/teams/{id}/squad")
    suspend fun squad(@Header("Authorization") auth: String, @Path("id") id: Int): ApiEnvelope<SpPart<List<SpSquadLineDto>>>

    @GET("mobile/v1/sports/teams/{id}/statistics")
    suspend fun teamStatistics(@Header("Authorization") auth: String, @Path("id") id: Int, @Query("competition") competition: Int? = null): ApiEnvelope<SpTeamStatsDto>

    @GET("mobile/v1/sports/teams/{id}/transfers")
    suspend fun transfers(@Header("Authorization") auth: String, @Path("id") id: Int): ApiEnvelope<SpPart<List<SpTransferDto>>>

    @GET("mobile/v1/sports/players/{id}")
    suspend fun player(@Header("Authorization") auth: String, @Path("id") id: Int, @Query("season") season: String? = null): ApiEnvelope<SpPlayerDto>

    @GET("mobile/v1/sports/players/{id}/career")
    suspend fun playerCareer(@Header("Authorization") auth: String, @Path("id") id: Int): ApiEnvelope<SpCareerDto>

    @GET("mobile/v1/sports/coaches/{id}")
    suspend fun coach(@Header("Authorization") auth: String, @Path("id") id: Int): ApiEnvelope<SpCoachDto>

    @GET("mobile/v1/sports/matches/{id}/insights")
    suspend fun insights(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<SpInsightsDto>

    @GET("mobile/v1/sports/widget")
    suspend fun widget(@Header("Authorization") auth: String, @Query("timezone") timezone: String): ApiEnvelope<SpWidgetDto>

    @GET("mobile/v1/sports/search")
    suspend fun search(@Header("Authorization") auth: String, @Query("q") q: String): ApiEnvelope<SpSearchDto>
}

data class SpPredictionDto(
    val winner: String? = null,
    @SerializedName("home_score") val homeScore: Int? = null,
    @SerializedName("away_score") val awayScore: Int? = null,
    val points: Int? = null,
    val exact: Boolean? = null,
    @SerializedName("correct_winner") val correctWinner: Boolean? = null,
    /** won · lost · void */
    val result: String? = null,
)

data class SpCrowdDto(val count: Int = 0, val home: Int = 0, val draw: Int = 0, val away: Int = 0, @SerializedName("top_score") val topScore: String? = null)

data class SpMyRatingDto(@SerializedName("fun") val funScore: Int = 0, val excitement: Int = 0)

data class SpRatingDto(val count: Int = 0, @SerializedName("fun") val funScore: Double? = null, val excitement: Double? = null, val mine: SpMyRatingDto? = null)

data class SpMyPrizeDto(val status: String? = null, @SerializedName("amount_minor") val amountMinor: Long? = null, @SerializedName("currency_code") val currencyCode: String? = null, @SerializedName("prize_type") val prizeType: String? = null)

data class SpBoardRowDto(val rank: Int = 0, val name: String? = null, val points: Int = 0, val exact: Int = 0, val me: Boolean = false)

/** A prediction contest. `rule`: exact · winner · points. `prize_type`: wallet · coupon · badge. */
data class SpContestDto(
    val id: String = "",
    val name: String? = null,
    val terms: String? = null,
    val scope: String? = null,
    val rule: String = "exact",
    val status: String = "open",
    @SerializedName("match_id") val matchId: String? = null,
    val match: String? = null,
    val competition: String? = null,
    val round: String? = null,
    @SerializedName("ends_at") val endsAt: String? = null,
    @SerializedName("prize_type") val prizeType: String = "wallet",
    @SerializedName("prize_amount_minor") val prizeAmountMinor: Long? = null,
    @SerializedName("prize_percent") val prizePercent: Int? = null,
    @SerializedName("currency_code") val currencyCode: String? = null,
    val distribution: String? = null,
    @SerializedName("max_winners") val maxWinners: Int? = null,
    @SerializedName("prizes_here") val prizesHere: Boolean = false,
    @SerializedName("my_prize") val myPrize: SpMyPrizeDto? = null,
    val leaderboard: List<SpBoardRowDto> = emptyList(),
    @SerializedName("my_points") val myPoints: Int = 0,
    val matches: List<SpMatchDto> = emptyList(),
)

data class SpPlayDto(
    val enabled: Boolean = true,
    val locked: Boolean = false,
    val mine: SpPredictionDto? = null,
    val crowd: SpCrowdDto? = null,
    val contests: List<SpContestDto> = emptyList(),
    val rating: SpRatingDto? = null,
)

data class SpPrizeDto(
    @SerializedName("contest_id") val contestId: String? = null,
    val name: String? = null,
    @SerializedName("prize_type") val prizeType: String = "wallet",
    @SerializedName("amount_minor") val amountMinor: Long? = null,
    @SerializedName("currency_code") val currencyCode: String? = null,
    val status: String = "pending",
    val points: Int = 0,
    @SerializedName("paid_at") val paidAt: String? = null,
)

data class SpSportDto(val key: String = "", val name: String? = null, val emoji: String? = null)

data class SpTeamDto(
    val id: Int = 0,
    val name: String? = null,
    val code: String? = null,
    val logo: String? = null,
    val national: Boolean = false,
    val color: String? = null,
    val sport: String? = null,
    val country: String? = null,
)

data class SpCompetitionDto(
    val id: Int = 0,
    val name: String? = null,
    val logo: String? = null,
    val country: String? = null,
    val flag: String? = null,
    val tier: String? = null,
    val sport: String? = null,
    val type: String? = null,
    val scope: String? = null,
    val season: String? = null,
)

data class SpPeriodDto(val label: String = "", val home: Int? = null, val away: Int? = null)

data class SpEventDto(
    val minute: Int? = null,
    val extra: Int? = null,
    val side: String? = null,
    /** goal · own_goal · penalty · missed_penalty · yellow · second_yellow · red · sub · var · other */
    val type: String = "other",
    val player: String? = null,
    @SerializedName("player_id") val playerId: Int? = null,
    val assist: String? = null,
    @SerializedName("assist_id") val assistId: Int? = null,
    val detail: String? = null,
)

data class SpStatDto(val type: String = "", val home: com.google.gson.JsonElement? = null, val away: com.google.gson.JsonElement? = null)

data class SpLineupPlayerDto(
    val id: Int? = null,
    val name: String? = null,
    val number: Int? = null,
    val pos: String? = null,
    val grid: String? = null,
    val photo: String? = null,
    val rating: Double? = null,
    val captain: Boolean = false,
    val goals: Int = 0,
    val yellow: Int = 0,
    val red: Int = 0,
)

data class SpLineupDto(
    val formation: String? = null,
    val coach: String? = null,
    @SerializedName("coach_id") val coachId: Int? = null,
    @SerializedName("coach_photo") val coachPhoto: String? = null,
    val start: List<SpLineupPlayerDto> = emptyList(),
    val subs: List<SpLineupPlayerDto> = emptyList(),
    val colors: Map<String, String?>? = null,
)

data class SpFollowingDto(val home: Boolean = false, val away: Boolean = false)

/**
 * A match in any sport. `status`: scheduled · live · break · finished · postponed · cancelled ·
 * suspended. `minute` is as of `synced_at` — the phone keeps it ticking until the next update.
 */
data class SpMatchDto(
    val id: String = "",
    val sport: String? = null,
    val competition: SpCompetitionDto? = null,
    val round: String? = null,
    val home: SpTeamDto? = null,
    val away: SpTeamDto? = null,
    @SerializedName("starts_at") val startsAt: String? = null,
    @SerializedName("local_date") val localDate: String? = null,
    @SerializedName("local_time") val localTime: String? = null,
    val status: String = "scheduled",
    @SerializedName("status_code") val statusCode: String? = null,
    val minute: Int? = null,
    @SerializedName("minute_extra") val minuteExtra: Int? = null,
    @SerializedName("home_score") val homeScore: Int? = null,
    @SerializedName("away_score") val awayScore: Int? = null,
    val periods: List<SpPeriodDto> = emptyList(),
    val winner: String? = null,
    val version: Int = 0,
    @SerializedName("synced_at") val syncedAt: String? = null,
    /** Races (F1): the Grand Prix; fights (MMA) use home / away for the fighters. */
    val title: String? = null,
    val race: SpRaceDto? = null,
    val fight: SpFightDto? = null,
    /** A race's classification (the match page), and its leader (live updates). */
    val results: List<SpRaceResultDto>? = null,
    val leader: String? = null,
    // the match page
    val venue: String? = null,
    val city: String? = null,
    val referee: String? = null,
    @SerializedName("status_label") val statusLabel: String? = null,
    val events: List<SpEventDto>? = null,
    val statistics: List<SpStatDto>? = null,
    val lineups: Map<String, SpLineupDto>? = null,
    /** The match sheet by side (ratings, captain…) and the poster (football). */
    val players: Map<String, List<SpSheetPlayerDto>>? = null,
    val poster: SpPosterDto? = null,
    val following: SpFollowingDto? = null,
    val channel: String? = null,
)

data class SpRaceDto(
    val type: String? = null,
    val laps: Int? = null,
    val lap: Int? = null,
    val circuit: String? = null,
    @SerializedName("circuit_image") val circuitImage: String? = null,
    val country: String? = null,
    val distance: String? = null,
    @SerializedName("fastest_lap") val fastestLap: String? = null,
)

data class SpRaceResultDto(
    val position: Int? = null,
    val driver: String? = null,
    val abbr: String? = null,
    val number: Int? = null,
    val image: String? = null,
    val team: String? = null,
    @SerializedName("team_logo") val teamLogo: String? = null,
    val time: String? = null,
    val gap: String? = null,
    val laps: Int? = null,
    val grid: String? = null,
    val pits: Int? = null,
)

data class SpFightDto(
    val category: String? = null,
    val main: Boolean = false,
    @SerializedName("won_by") val wonBy: String? = null,
    val round: Int? = null,
    val time: String? = null,
)

data class SpCompetitionGroupDto(val competition: SpCompetitionDto? = null, val followed: Boolean = false, val matches: List<SpMatchDto> = emptyList())

data class SpFollowingIdsDto(val teams: List<Int> = emptyList(), val competitions: List<Int> = emptyList())

data class SpPrefsDto(
    @SerializedName("no_spoilers") val noSpoilers: Boolean = false,
    /** off · calm · normal · festive */
    val celebration: String = "normal",
    val sounds: Boolean = true,
    val vibrate: Boolean = true,
    @SerializedName("reminder_minutes") val reminderMinutes: Int = 15,
    @SerializedName("goals_in_quiet") val goalsInQuiet: Boolean = false,
)

data class SpHomeDto(
    val date: String? = null,
    val sports: List<SpSportDto> = emptyList(),
    @SerializedName("live_count") val liveCount: Int = 0,
    val mine: List<SpMatchDto> = emptyList(),
    val competitions: List<SpCompetitionGroupDto> = emptyList(),
    val following: SpFollowingIdsDto? = null,
    val preferences: SpPrefsDto? = null,
)

data class SpStandingRowDto(
    val rank: Int = 0,
    val team: SpTeamDto? = null,
    val points: Int = 0,
    val played: Int = 0,
    val win: Int = 0,
    val draw: Int = 0,
    val lose: Int = 0,
    @SerializedName("goals_for") val goalsFor: Int = 0,
    @SerializedName("goals_against") val goalsAgainst: Int = 0,
    @SerializedName("goal_diff") val goalDiff: Int = 0,
    val form: String? = null,
    val description: String? = null,
    /** up · down · same */
    val trend: String? = null,
    val home: SpSplitDto? = null,
    val away: SpSplitDto? = null,
)

data class SpStandingGroupDto(val group: String = "", val rows: List<SpStandingRowDto> = emptyList())

data class SpScorerDto(val rank: Int = 0, val player: String? = null, val photo: String? = null, val team: String? = null, @SerializedName("team_logo") val teamLogo: String? = null, val goals: Int = 0, val assists: Int = 0)

data class SpCompTeamDto(
    val id: Int = 0,
    val name: String? = null,
    val logo: String? = null,
    val color: String? = null,
    val country: String? = null,
    val following: Boolean = false,
)

data class SpCompetitionPageDto(
    val id: Int = 0,
    val name: String? = null,
    val logo: String? = null,
    val country: String? = null,
    val flag: String? = null,
    val sport: String? = null,
    val season: String? = null,
    val standings: List<SpStandingGroupDto> = emptyList(),
    @SerializedName("standings_updated_at") val standingsUpdatedAt: String? = null,
    @SerializedName("top_scorers") val topScorers: List<SpScorerDto> = emptyList(),
    /** ok · pending (not read yet) · unavailable (the provider has none for this season) */
    @SerializedName("standings_state") val standingsState: String? = null,
    @SerializedName("scorers_state") val scorersState: String? = null,
    val teams: List<SpCompTeamDto> = emptyList(),
    val live: List<SpMatchDto> = emptyList(),
    val next: List<SpMatchDto> = emptyList(),
    val last: List<SpMatchDto> = emptyList(),
    val following: Boolean = false,
)

data class SpTeamFollowDto(val id: Int = 0, val alerts: Map<String, Boolean> = emptyMap(), @SerializedName("no_spoilers") val noSpoilers: Boolean = false)

data class SpTeamPageDto(
    val id: Int = 0,
    val name: String? = null,
    val logo: String? = null,
    val color: String? = null,
    val national: Boolean = false,
    val sport: String? = null,
    val country: String? = null,
    val code: String? = null,
    val founded: Int? = null,
    val venue: SpVenueDto? = null,
    val coach: SpPersonDto? = null,
    val form: List<SpFormDto> = emptyList(),
    val tables: List<SpTeamTableDto> = emptyList(),
    val live: List<SpMatchDto> = emptyList(),
    val next: List<SpMatchDto> = emptyList(),
    val last: List<SpMatchDto> = emptyList(),
    val follow: SpTeamFollowDto? = null,
)

data class SpFollowTargetDto(val id: Int = 0, val name: String? = null, val logo: String? = null, val color: String? = null, val country: String? = null, val flag: String? = null)

data class SpFollowDto(
    val id: Int = 0,
    val kind: String = "team",
    val sport: String? = null,
    val target: SpFollowTargetDto? = null,
    val alerts: Map<String, Boolean> = emptyMap(),
    @SerializedName("no_spoilers") val noSpoilers: Boolean = false,
)
