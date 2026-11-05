package com.dorr.app.network

import com.google.gson.annotations.SerializedName
import okhttp3.MultipartBody
import retrofit2.http.Body
import retrofit2.http.DELETE
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.Multipart
import retrofit2.http.POST
import retrofit2.http.Part
import retrofit2.http.Path
import retrofit2.http.Query
import retrofit2.http.Streaming

/**
 * Watch & Earn (docs/modules/watch-earn/API.md) under /api/mobile/v1/watch-earn: the viewer (feed, the
 * watching session, comments, reactions, reports, my rewards), the advertiser (the wizard's options, my ads,
 * one ad's stats and its actions) and the ad wallet (fund / return, each previewed first).
 *
 * Money is always an integer in minor units with the `currency.decimals` sent next to it. These requests
 * carry the wallet's `X-Country` like [WalletApi] (see [WalletCountry.headerFor]).
 */
interface WatchEarnApi {
    // ------------------------------------------------------------------ the viewer

    /** `{enabled, player: {mode, image_seconds, interaction_window_minutes}, ads}` — my city's ads first. */
    @GET("mobile/v1/watch-earn/feed")
    suspend fun feed(@Header("Authorization") auth: String): ApiEnvelope<WeFeedDto>

    /**
     * One ad I already watched or commented on (opened from a push): `{ad, watched, can_interact, interaction_deadline_at}`.
     * No session, no reward; anyone else gets `not_found` (404).
     */
    @GET("mobile/v1/watch-earn/ads/{ad}")
    suspend fun feedAd(@Header("Authorization") auth: String, @Path("ad") ad: String): ApiEnvelope<WeAdPageDto>

    /** Fails with `watch_earn_not_eligible` (403) or `watch_earn_already_watched` (409). */
    @POST("mobile/v1/watch-earn/ads/{ad}/start")
    suspend fun start(@Header("Authorization") auth: String, @Path("ad") ad: String, @Body body: WeStartRequest): ApiEnvelope<WeStartDto>

    /** `watch_earn_too_fast` (422) when less than `seconds` passed since start; `watch_earn_ad_unavailable` (409). Safe to repeat. */
    @POST("mobile/v1/watch-earn/views/{view}/complete")
    suspend fun complete(@Header("Authorization") auth: String, @Path("view") view: String): ApiEnvelope<WeCompleteDto>

    @GET("mobile/v1/watch-earn/ads/{ad}/comments")
    suspend fun comments(@Header("Authorization") auth: String, @Path("ad") ad: String, @Query("cursor") cursor: String? = null): ApiEnvelope<WeCommentsDto>

    /** Only after watching the ad to the end (`watch_earn_watch_first`). */
    @POST("mobile/v1/watch-earn/ads/{ad}/comments")
    suspend fun comment(@Header("Authorization") auth: String, @Path("ad") ad: String, @Body body: WeCommentRequest): ApiEnvelope<WeCommentDto>

    /** Answers 204 with no body, so the raw response (a refusal is turned into an HttpException by the caller). */
    @DELETE("mobile/v1/watch-earn/ads/{ad}/comments/{id}")
    suspend fun deleteComment(@Header("Authorization") auth: String, @Path("ad") ad: String, @Path("id") id: Long): retrofit2.Response<Unit>

    @POST("mobile/v1/watch-earn/ads/{ad}/reaction")
    suspend fun react(@Header("Authorization") auth: String, @Path("ad") ad: String, @Body body: WeReactionRequest): ApiEnvelope<WeReactionsDto>

    @DELETE("mobile/v1/watch-earn/ads/{ad}/reaction")
    suspend fun unreact(@Header("Authorization") auth: String, @Path("ad") ad: String): ApiEnvelope<WeReactionsDto>

    /** `reason`: misleading, offensive, adult, violence, scam, other. One report per viewer. */
    @POST("mobile/v1/watch-earn/ads/{ad}/report")
    suspend fun reportAd(@Header("Authorization") auth: String, @Path("ad") ad: String, @Body body: WeReportRequest): ApiEnvelope<Any?>

    @GET("mobile/v1/watch-earn/rewards")
    suspend fun rewards(@Header("Authorization") auth: String, @Query("cursor") cursor: String? = null): ApiEnvelope<WeRewardsDto>

    // ------------------------------------------------------------------ the advertiser

    @GET("mobile/v1/watch-earn/advertiser/options")
    suspend fun options(@Header("Authorization") auth: String): ApiEnvelope<WeOptionsDto>

    @POST("mobile/v1/watch-earn/advertiser/audience")
    suspend fun audience(@Header("Authorization") auth: String, @Body body: WeAudienceRequest): ApiEnvelope<WeAudienceDto>

    @GET("mobile/v1/watch-earn/advertiser/ads")
    suspend fun ads(
        @Header("Authorization") auth: String,
        @Query("status") status: String? = null,
        @Query("page") page: Int = 1,
    ): ApiEnvelope<List<WeAdDto>>

    /**
     * Multipart: `media`, `media_seconds?`, `title`, `body?`, `link_url?`, `cities[]?`, `target_gender?`,
     * `target_locale?`, `starts_at`, `ends_at`, `budget_minor`. Reserves the budget; the ad waits for the review.
     */
    @Multipart
    @POST("mobile/v1/watch-earn/advertiser/ads")
    suspend fun createAd(@Header("Authorization") auth: String, @Part parts: List<MultipartBody.Part>): ApiEnvelope<WeAdDto>

    @GET("mobile/v1/watch-earn/advertiser/ads/{ad}")
    suspend fun ad(@Header("Authorization") auth: String, @Path("ad") ad: String): ApiEnvelope<WeAdDto>

    @GET("mobile/v1/watch-earn/advertiser/ads/{ad}/stats")
    suspend fun stats(@Header("Authorization") auth: String, @Path("ad") ad: String): ApiEnvelope<WeStatsResponseDto>

    /** The stats as a file (`format`: pdf / xlsx), sent as an attachment — the raw bytes, not the JSON envelope. */
    @Streaming
    @GET("mobile/v1/watch-earn/advertiser/ads/{ad}/export")
    suspend fun export(@Header("Authorization") auth: String, @Path("ad") ad: String, @Query("format") format: String): retrofit2.Response<okhttp3.ResponseBody>

    /** A content edit (multipart, the file optional): the ad goes back to the review. */
    @Multipart
    @POST("mobile/v1/watch-earn/advertiser/ads/{ad}")
    suspend fun updateAd(@Header("Authorization") auth: String, @Path("ad") ad: String, @Part parts: List<MultipartBody.Part>): ApiEnvelope<WeAdDto>

    @POST("mobile/v1/watch-earn/advertiser/ads/{ad}/budget")
    suspend fun addBudget(@Header("Authorization") auth: String, @Path("ad") ad: String, @Body body: WeAmountRequest): ApiEnvelope<WeAdDto>

    @POST("mobile/v1/watch-earn/advertiser/ads/{ad}/extend")
    suspend fun extend(@Header("Authorization") auth: String, @Path("ad") ad: String, @Body body: WeExtendRequest): ApiEnvelope<WeAdDto>

    @POST("mobile/v1/watch-earn/advertiser/ads/{ad}/pause")
    suspend fun pause(@Header("Authorization") auth: String, @Path("ad") ad: String): ApiEnvelope<WeAdDto>

    @POST("mobile/v1/watch-earn/advertiser/ads/{ad}/resume")
    suspend fun resume(@Header("Authorization") auth: String, @Path("ad") ad: String): ApiEnvelope<WeAdDto>

    /** Permanent: the rest of the budget goes back to the free balance. */
    @POST("mobile/v1/watch-earn/advertiser/ads/{ad}/stop")
    suspend fun stop(@Header("Authorization") auth: String, @Path("ad") ad: String): ApiEnvelope<WeAdDto>

    /** `reason`: offensive, spam, misleading, harassment, other. The advertiser reports, never deletes (D21). */
    @POST("mobile/v1/watch-earn/advertiser/ads/{ad}/comments/{comment}/report")
    suspend fun reportComment(
        @Header("Authorization") auth: String,
        @Path("ad") ad: String,
        @Path("comment") comment: Long,
        @Body body: WeReportRequest,
    ): ApiEnvelope<Any?>

    // ------------------------------------------------------------------ the ad wallet

    /** Free / locked by bucket, the ads holding money, the main wallet by bucket, and the statement (cursor). */
    @GET("mobile/v1/watch-earn/ad-wallet")
    suspend fun adWallet(@Header("Authorization") auth: String, @Query("cursor") cursor: String? = null): ApiEnvelope<WeAdWalletDto>

    @POST("mobile/v1/watch-earn/ad-wallet/fund/preview")
    suspend fun fundPreview(@Header("Authorization") auth: String, @Body body: WeAmountRequest): ApiEnvelope<WeFundPreviewDto>

    /** Needs the wallet PIN; the `idempotency_key` is made once per attempt. Answers the ad wallet again. */
    @POST("mobile/v1/watch-earn/ad-wallet/fund")
    suspend fun fund(@Header("Authorization") auth: String, @Header("X-Wallet-Pin") pin: String, @Body body: WeMoveRequest): ApiEnvelope<WeAdWalletDto>

    @POST("mobile/v1/watch-earn/ad-wallet/return/preview")
    suspend fun returnPreview(@Header("Authorization") auth: String, @Body body: WeAmountRequest): ApiEnvelope<WeReturnPreviewDto>

    /** The free balance only; what ads hold comes back when they stop or end. */
    @POST("mobile/v1/watch-earn/ad-wallet/return")
    suspend fun returnToWallet(@Header("Authorization") auth: String, @Body body: WeMoveRequest): ApiEnvelope<WeAdWalletDto>
}

// ---------------------------------------------------------------------- shared

/** Every response that shows money sends its currency: the code and how many decimals a minor unit has. */
data class WeCurrencyDto(
    val code: String? = null,
    val decimals: Int = 2,
)

/** An amount split by the wallet bucket it came from (reward → spend_only → withdrawable). */
data class WeBucketsDto(
    @SerializedName("reward_minor") val rewardMinor: Long = 0,
    @SerializedName("spend_only_minor") val spendOnlyMinor: Long = 0,
    @SerializedName("withdrawable_minor") val withdrawableMinor: Long = 0,
) {
    val totalMinor: Long get() = rewardMinor + spendOnlyMinor + withdrawableMinor
}

data class WeAdRefDto(
    val id: String = "",
    val title: String? = null,
)

data class WeMediaDto(
    /** image / video */
    val type: String = "image",
    val url: String? = null,
    val seconds: Int? = null,
    val size: Long? = null,
    val version: Int? = null,
)

data class WeCountsDto(
    val impressions: Int = 0,
    val completed: Int = 0,
    val interactions: Int = 0,
    val comments: Int = 0,
    val reactions: Int = 0,
    val reports: Int = 0,
)

// ---------------------------------------------------------------------- the viewer

data class WeFeedDto(
    val enabled: Boolean = false,
    val player: WePlayerDto = WePlayerDto(),
    val ads: List<WeFeedAdDto> = emptyList(),
)

data class WePlayerDto(
    /** auto: on to the next ad by itself; manual: the ad closes at the end and a tap goes on. */
    val mode: String = "auto",
    @SerializedName("image_seconds") val imageSeconds: Int = 8,
    @SerializedName("interaction_window_minutes") val interactionWindowMinutes: Int = 10,
)

data class WeFeedAdDto(
    val id: String = "",
    val title: String = "",
    val body: String? = null,
    @SerializedName("link_url") val linkUrl: String? = null,
    val media: WeMediaDto = WeMediaDto(),
    val advertiser: WeAdvertiserDto? = null,
    @SerializedName("in_my_city") val inMyCity: Boolean? = null,
    val currency: WeCurrencyDto = WeCurrencyDto(),
    val earn: WeEarnDto = WeEarnDto(),
    val counts: WeCountsDto = WeCountsDto(),
)

data class WeAdvertiserDto(
    val name: String? = null,
    val avatar: String? = null,
)

/** What the viewer earns: for watching to the end, and for the first comment or reaction in the window. */
data class WeEarnDto(
    @SerializedName("view_minor") val viewMinor: Long = 0,
    @SerializedName("interaction_minor") val interactionMinor: Long = 0,
)

data class WeStartRequest(val platform: String = "android")

data class WeStartDto(
    @SerializedName("view_id") val viewId: String = "",
    @SerializedName("started_at") val startedAt: String? = null,
    val seconds: Int? = null,
)

data class WeCompleteDto(
    @SerializedName("view_id") val viewId: String? = null,
    @SerializedName("completed_at") val completedAt: String? = null,
    @SerializedName("interaction_deadline_at") val interactionDeadlineAt: String? = null,
    @SerializedName("can_interact") val canInteract: Boolean = false,
)

/** `GET ads/{ad}`: the ad as the feed shows it, whether I watched it, and whether interacting still earns. */
data class WeAdPageDto(
    val ad: WeFeedAdDto = WeFeedAdDto(),
    val watched: Boolean = false,
    @SerializedName("can_interact") val canInteract: Boolean = false,
    @SerializedName("interaction_deadline_at") val interactionDeadlineAt: String? = null,
)

data class WeCommentUserDto(
    val id: Int = 0,
    val name: String? = null,
    val avatar: String? = null,
)

data class WeCommentDto(
    val id: Long = 0,
    val body: String = "",
    @SerializedName("created_at") val createdAt: String? = null,
    val user: WeCommentUserDto? = null,
)

data class WeCommentsDto(
    val comments: List<WeCommentDto> = emptyList(),
    @SerializedName("next_cursor") val nextCursor: String? = null,
    /** emoji → count (an empty PHP array arrives as `[]`, which Gson reads as an empty map). */
    val reactions: Map<String, Int> = emptyMap(),
    @SerializedName("my_reaction") val myReaction: String? = null,
)

data class WeCommentRequest(val body: String)

data class WeReactionRequest(val emoji: String)

data class WeReactionsDto(
    val reactions: Map<String, Int> = emptyMap(),
    @SerializedName("my_reaction") val myReaction: String? = null,
)

data class WeReportRequest(val reason: String, val note: String? = null)

data class WeRewardsDto(
    val currency: WeCurrencyDto = WeCurrencyDto(),
    @SerializedName("waiting_minor") val waitingMinor: Long = 0,
    @SerializedName("paid_minor") val paidMinor: Long = 0,
    val items: List<WeRewardItemDto> = emptyList(),
    @SerializedName("next_cursor") val nextCursor: String? = null,
)

data class WeRewardItemDto(
    val id: Long = 0,
    /** view / interaction */
    val kind: String = "view",
    @SerializedName("amount_minor") val amountMinor: Long = 0,
    val ad: WeAdRefDto? = null,
    @SerializedName("available_at") val availableAt: String? = null,
    @SerializedName("released_at") val releasedAt: String? = null,
    @SerializedName("created_at") val createdAt: String? = null,
)

// ---------------------------------------------------------------------- the advertiser

data class WeOptionsDto(
    val enabled: Boolean = false,
    val country: WeCountryDto? = null,
    val currency: WeCurrencyDto = WeCurrencyDto(),
    @SerializedName("price_per_view_minor") val pricePerViewMinor: Long = 0,
    @SerializedName("min_ad_wallet_minor") val minAdWalletMinor: Long = 0,
    /** The ad wallet's free balance. */
    @SerializedName("free_minor") val freeMinor: Long = 0,
    val media: WeMediaLimitsDto = WeMediaLimitsDto(),
    val cities: List<WeCityDto> = emptyList(),
    val genders: List<String> = emptyList(),
    val locales: List<String> = emptyList(),
)

data class WeCountryDto(
    val id: Int = 0,
    val code: String? = null,
    val name: String? = null,
)

data class WeMediaLimitsDto(
    @SerializedName("image_mimes") val imageMimes: List<String> = listOf("image/jpeg", "image/png", "image/webp"),
    @SerializedName("video_mimes") val videoMimes: List<String> = listOf("video/mp4", "video/quicktime", "video/webm"),
    @SerializedName("image_seconds") val imageSeconds: Int = 8,
    @SerializedName("video_max_seconds") val videoMaxSeconds: Int = 60,
    @SerializedName("video_max_mb") val videoMaxMb: Int = 50,
)

data class WeCityDto(
    val id: Int = 0,
    val name: String? = null,
)

data class WeAudienceRequest(
    val cities: List<Int>? = null,
    val gender: String? = null,
    val locale: String? = null,
)

data class WeAudienceDto(val people: Long = 0)

data class WeAdDto(
    val id: String = "",
    /** draft, pending_review, rejected, active, paused, exhausted, suspended, ended, stopped */
    val status: String = "pending_review",
    /** Active, but its start date is still ahead. */
    val scheduled: Boolean = false,
    val title: String = "",
    val body: String? = null,
    @SerializedName("link_url") val linkUrl: String? = null,
    val media: WeMediaDto = WeMediaDto(),
    val audience: WeAudienceInfoDto = WeAudienceInfoDto(),
    @SerializedName("starts_at") val startsAt: String? = null,
    @SerializedName("ends_at") val endsAt: String? = null,
    val currency: WeCurrencyDto = WeCurrencyDto(),
    val budget: WeBudgetDto = WeBudgetDto(),
    val counts: WeCountsDto = WeCountsDto(),
    @SerializedName("reject_reason") val rejectReason: String? = null,
    @SerializedName("submitted_at") val submittedAt: String? = null,
    @SerializedName("reviewed_at") val reviewedAt: String? = null,
    @SerializedName("suspended_at") val suspendedAt: String? = null,
    @SerializedName("closed_at") val closedAt: String? = null,
    @SerializedName("created_at") val createdAt: String? = null,
)

data class WeAudienceInfoDto(
    @SerializedName("country_id") val countryId: Int? = null,
    /** City ids; empty = the whole country. */
    val cities: List<Int>? = null,
    val gender: String? = null,
    val locale: String? = null,
)

data class WeBudgetDto(
    @SerializedName("total_minor") val totalMinor: Long = 0,
    @SerializedName("spent_minor") val spentMinor: Long = 0,
    @SerializedName("remaining_minor") val remainingMinor: Long = 0,
    @SerializedName("price_per_view_minor") val pricePerViewMinor: Long = 0,
    @SerializedName("views_left") val viewsLeft: Long = 0,
    @SerializedName("remaining_by_bucket") val remainingByBucket: WeBucketsDto = WeBucketsDto(),
)

data class WeStatsResponseDto(
    val ad: WeAdDto = WeAdDto(),
    val stats: WeStatsDto = WeStatsDto(),
)

data class WeStatsDto(
    val totals: WeTotalsDto = WeTotalsDto(),
    val money: WeStatsMoneyDto = WeStatsMoneyDto(),
    val daily: List<WeDailyDto> = emptyList(),
    @SerializedName("by_city") val byCity: List<WeCityStatDto> = emptyList(),
    /** male / female / unknown → completed views */
    @SerializedName("by_gender") val byGender: Map<String, Int> = emptyMap(),
    /** android / ios / web / unknown → completed views */
    @SerializedName("by_platform") val byPlatform: Map<String, Int> = emptyMap(),
    /** 24 numbers, hour 0 to 23. */
    @SerializedName("by_hour") val byHour: List<Int> = emptyList(),
    /** emoji → count */
    val reactions: Map<String, Int> = emptyMap(),
)

data class WeTotalsDto(
    val impressions: Int = 0,
    val completed: Int = 0,
    @SerializedName("completion_rate") val completionRate: Double = 0.0,
    val interactions: Int = 0,
    @SerializedName("interaction_rate") val interactionRate: Double = 0.0,
    val comments: Int = 0,
    val reactions: Int = 0,
    val reports: Int = 0,
    @SerializedName("unique_viewers") val uniqueViewers: Int = 0,
)

data class WeStatsMoneyDto(
    @SerializedName("budget_minor") val budgetMinor: Long = 0,
    @SerializedName("spent_minor") val spentMinor: Long = 0,
    @SerializedName("remaining_minor") val remainingMinor: Long = 0,
    @SerializedName("price_per_view_minor") val pricePerViewMinor: Long = 0,
    @SerializedName("views_left") val viewsLeft: Long = 0,
    @SerializedName("cost_per_interaction_minor") val costPerInteractionMinor: Long? = null,
    @SerializedName("spent_percent") val spentPercent: Double = 0.0,
    @SerializedName("daily_average_minor") val dailyAverageMinor: Long = 0,
)

data class WeDailyDto(
    /** yyyy-MM-dd */
    val date: String = "",
    val completed: Int = 0,
    val interactions: Int = 0,
)

data class WeCityStatDto(
    @SerializedName("city_id") val cityId: Int? = null,
    val name: String? = null,
    val completed: Int = 0,
)

data class WeAmountRequest(@SerializedName("amount_minor") val amountMinor: Long)

/** `ends_at`: a later end date ("yyyy-MM-dd HH:mm:ss"). */
data class WeExtendRequest(@SerializedName("ends_at") val endsAt: String)

data class WeMoveRequest(
    @SerializedName("amount_minor") val amountMinor: Long,
    @SerializedName("idempotency_key") val idempotencyKey: String,
)

// ---------------------------------------------------------------------- the ad wallet

data class WeAdWalletDto(
    val currency: WeCurrencyDto = WeCurrencyDto(),
    val free: WeBucketsDto = WeBucketsDto(),
    val locked: WeBucketsDto = WeBucketsDto(),
    @SerializedName("total_minor") val totalMinor: Long = 0,
    val ads: List<WeHoldingAdDto> = emptyList(),
    /** The main wallet's available balance by bucket — what a fund can take from. */
    @SerializedName("main_wallet") val mainWallet: WeBucketsDto = WeBucketsDto(),
    val entries: List<WeEntryDto> = emptyList(),
    @SerializedName("next_cursor") val nextCursor: String? = null,
)

data class WeHoldingAdDto(
    val id: String = "",
    val title: String = "",
    val status: String = "",
    @SerializedName("locked_minor") val lockedMinor: Long = 0,
    @SerializedName("budget_minor") val budgetMinor: Long = 0,
    @SerializedName("spent_minor") val spentMinor: Long = 0,
)

data class WeEntryDto(
    val id: Long = 0,
    /** fund, topup, return, reserve, release */
    val type: String = "",
    @SerializedName("amount_minor") val amountMinor: Long = 0,
    @SerializedName("by_bucket") val byBucket: WeBucketsDto = WeBucketsDto(),
    @SerializedName("free_after_minor") val freeAfterMinor: Long = 0,
    val ad: WeAdRefDto? = null,
    @SerializedName("created_at") val createdAt: String? = null,
)

data class WeFundPreviewDto(val take: WeBucketsDto = WeBucketsDto())

data class WeReturnPreviewDto(val give: WeBucketsDto = WeBucketsDto())
