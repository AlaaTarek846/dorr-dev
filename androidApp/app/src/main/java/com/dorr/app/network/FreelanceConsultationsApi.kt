package com.dorr.app.network

import com.google.gson.annotations.SerializedName
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.POST
import retrofit2.http.Path
import retrofit2.http.Query

/**
 * FL-L07, paid consultations on the client's side (`mobile/v1/freelance`): the consultations on offer (a list of their own, next to the
 * ready-made services), one with its free times (UTC instants, shown on the phone's clock), booking one (the money is held from the
 * wallet at once; a `request_id` makes a double tap one booking), my consultations, and cancelling, confirming or complaining about one.
 */
interface FreelanceConsultationsApi {
    @GET("mobile/v1/freelance/consultation-offers")
    suspend fun market(@Header("Authorization") auth: String, @Query("search") search: String? = null, @Query("page") page: Int = 1, @Query("per_page") perPage: Int = 15): ApiEnvelope<List<ConsultCardDto>>

    @GET("mobile/v1/freelance/consultation-offers/{uuid}")
    suspend fun offer(@Header("Authorization") auth: String, @Path("uuid") offer: String): ApiEnvelope<ConsultOfferDto>

    @GET("mobile/v1/freelance/consultation-offers/{uuid}/slots")
    suspend fun slots(@Header("Authorization") auth: String, @Path("uuid") offer: String, @Query("minutes") minutes: Int): ApiEnvelope<ConsultSlotsDto>

    /** `tab` = upcoming · past · cancelled */
    @GET("mobile/v1/freelance/consultations")
    suspend fun list(@Header("Authorization") auth: String, @Query("tab") tab: String? = null, @Query("page") page: Int = 1, @Query("per_page") perPage: Int = 15): ApiEnvelope<List<ConsultDto>>

    @GET("mobile/v1/freelance/consultations/{uuid}")
    suspend fun show(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<ConsultDto>

    @POST("mobile/v1/freelance/consultations")
    suspend fun book(@Header("Authorization") auth: String, @Body body: ConsultBookRequest): ApiEnvelope<ConsultDto>

    @POST("mobile/v1/freelance/consultations/{uuid}/cancel")
    suspend fun cancel(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Body body: ConsultReasonRequest = ConsultReasonRequest()): ApiEnvelope<ConsultDto>

    @POST("mobile/v1/freelance/consultations/{uuid}/confirm")
    suspend fun confirm(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<ConsultDto>

    /** Once it is over and paid: 1–5 stars and a comment, once; it shows on the freelancer's profile at once. */
    @POST("mobile/v1/freelance/consultations/{uuid}/review")
    suspend fun review(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Body body: ConsultReviewRequest): ApiEnvelope<ConsultDto>

    @POST("mobile/v1/freelance/consultations/{uuid}/complain")
    suspend fun complain(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Body body: ConsultComplaintRequest): ApiEnvelope<ConsultDto>

    // FL-L16: the consultation's video session (a LiveKit room, recorded).

    /** The room's state, who came, and the recordings with fresh signed links (good for 15 minutes). */
    @GET("mobile/v1/freelance/consultations/{uuid}/session")
    suspend fun session(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<ConsultSessionDto>

    /** A ticket into the room. 409 `freelance_consultation_session_not_yet` (`data.opens_at`) · `_over` · `_closed` · `_not_booked`; 503 `_unavailable`. */
    @POST("mobile/v1/freelance/consultations/{uuid}/session/join")
    suspend fun joinSession(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<ConsultJoinDto>

    /** The app is in the room now: attendance starts, the recording starts, the other side is told the first time. */
    @POST("mobile/v1/freelance/consultations/{uuid}/session/connected")
    suspend fun sessionConnected(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<ConsultSessionDto>

    /** The app left the room (coming back while time is left is fine). */
    @POST("mobile/v1/freelance/consultations/{uuid}/session/leave")
    suspend fun leaveSession(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<ConsultSessionDto>

    /** The freelancer never came in (`can.no_show`): a complaint for the staff with the attendance as proof. */
    @POST("mobile/v1/freelance/consultations/{uuid}/no-show")
    suspend fun noShow(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<ConsultDto>
}

/** On a freelancer's profile: one of the consultations they list now, from this total, in these lengths. */
data class ConsultTeaserDto(
    val uuid: String,
    val title: String? = null,
    @SerializedName("from_minor") val fromMinor: Long = 0,
    @field:com.google.gson.annotations.JsonAdapter(CurrencyLabelAdapter::class) val currency: String? = null,
    val minutes: List<Int> = emptyList(),
)

/** One consultation as a card in the list: who, what, from what price and the first free time. */
data class ConsultCardDto(
    val uuid: String,
    /** The freelancer's country (the market mixes every open country). */
    val country: FlCountryRefDto? = null,
    val title: String? = null,
    val about: String? = null,
    val minutes: List<Int> = emptyList(),
    @SerializedName("from_minor") val fromMinor: Long = 0,
    @field:com.google.gson.annotations.JsonAdapter(CurrencyLabelAdapter::class) val currency: String? = null,
    val decimals: Int = 2,
    @SerializedName("next_slot") val nextSlot: String? = null,
    val freelancer: ConsultFreelancerDto = ConsultFreelancerDto(),
)

data class ConsultOfferDto(
    val uuid: String? = null,
    val freelancer: ConsultFreelancerDto = ConsultFreelancerDto(),
    val title: String? = null,
    val about: String? = null,
    val options: List<ConsultOptionDto> = emptyList(),
    @field:com.google.gson.annotations.JsonAdapter(CurrencyLabelAdapter::class) val currency: String? = null,
    val decimals: Int = 2,
    val timezone: String? = null,
    @SerializedName("next_slot") val nextSlot: String? = null,
    val rules: ConsultRulesDto = ConsultRulesDto(),
    /** FL-L09: the freelancer's loyalty program as it stands for me (null: none). */
    val loyalty: FlLoyaltyViewDto? = null,
)

data class ConsultFreelancerDto(
    val uuid: String? = null,
    val name: String? = null,
    val headline: String? = null,
    @SerializedName("avatar_url") val avatarUrl: String? = null,
    val rating: Double? = null,
    @SerializedName("reviews_count") val reviewsCount: Int = 0,
    @SerializedName("completed_projects") val completedProjects: Int = 0,
    @SerializedName("consultations_done") val consultationsDone: Int = 0,
    /** What clients said (on the page before booking), from projects and consultations. */
    val reviews: List<FreelanceReviewLineDto> = emptyList(),
)

data class ConsultOptionDto(
    val minutes: Int,
    @SerializedName("price_minor") val priceMinor: Long = 0,
    @SerializedName("client_fee_minor") val clientFeeMinor: Long = 0,
    @SerializedName("total_minor") val totalMinor: Long = 0,
    /** FL-L09: the length's usual price and the loyalty discount (basis points) taken off [priceMinor]; null: none. */
    @SerializedName("list_price_minor") val listPriceMinor: Long? = null,
    @SerializedName("loyalty_bp") val loyaltyBp: Int? = null,
)

data class ConsultRulesDto(
    @SerializedName("free_cancel_hours") val freeCancelHours: Int = 24,
    @SerializedName("complaint_hours") val complaintHours: Int = 24,
    @SerializedName("response_hours") val responseHours: Int = 24,
    @SerializedName("max_days_ahead") val maxDaysAhead: Int = 30,
)

data class ConsultSlotsDto(val minutes: Int = 30, val slots: List<String> = emptyList())

data class ConsultBookRequest(
    val offer: String,
    val minutes: Int,
    @SerializedName("starts_at") val startsAt: String,
    val topic: String,
    @SerializedName("request_id") val requestId: String,
    /** FL-L16: the session is recorded; booking needs the client's yes. */
    @SerializedName("recording_consent") val recordingConsent: Boolean = true,
)

data class ConsultReasonRequest(val reason: String? = null)

data class ConsultComplaintRequest(val text: String)

data class ConsultReviewRequest(val stars: Int, val comment: String? = null)

/** The client's rating of one consultation: whether it can be rated now (and until when), and the one given. */
data class ConsultReviewDto(
    @SerializedName("can_rate") val canRate: Boolean = false,
    @SerializedName("rate_until") val rateUntil: String? = null,
    val rating: ConsultRatingDto? = null,
)

data class ConsultRatingDto(val stars: Double = 0.0, val comment: String? = null, val removed: Boolean = false, val at: String? = null)

data class ConsultDto(
    val uuid: String,
    /** booked · completed · cancelled · disputed · refunded */
    val status: String,
    /** upcoming · live · ended while booked, else the status */
    val phase: String,
    val offer: String? = null,
    val title: String? = null,
    val minutes: Int = 30,
    @SerializedName("starts_at") val startsAt: String,
    @SerializedName("ends_at") val endsAt: String,
    val topic: String? = null,
    @field:com.google.gson.annotations.JsonAdapter(CurrencyLabelAdapter::class) val currency: String? = null,
    val decimals: Int = 2,
    /** FL-L09: the usual price and the loyalty discount taken off it; null: none. */
    @SerializedName("list_price_minor") val listPriceMinor: Long? = null,
    @SerializedName("loyalty_bp") val loyaltyBp: Int? = null,
    @SerializedName("saving_minor") val savingMinor: Long? = null,
    val money: ConsultMoneyDto = ConsultMoneyDto(),
    val freelancer: ConsultFreelancerDto? = null,
    val rules: ConsultBookingRulesDto = ConsultBookingRulesDto(),
    @SerializedName("respond_by") val respondBy: String? = null,
    val cancel: ConsultCancelDto? = null,
    val complaint: ConsultComplaintDto? = null,
    val resolution: ConsultResolutionDto? = null,
    val review: ConsultReviewDto = ConsultReviewDto(),
    val can: ConsultCanDto = ConsultCanDto(),
    /** FL-L16: the video session (null while it is not booked yet on older servers). */
    val session: ConsultSessionDto? = null,
)

data class ConsultMoneyDto(
    @SerializedName("price_minor") val priceMinor: Long = 0,
    @SerializedName("client_fee_minor") val clientFeeMinor: Long = 0,
    @SerializedName("total_minor") val totalMinor: Long = 0,
)

data class ConsultBookingRulesDto(
    @SerializedName("free_cancel_until") val freeCancelUntil: String? = null,
    @SerializedName("release_at") val releaseAt: String? = null,
    @SerializedName("free_cancel_hours") val freeCancelHours: Int = 24,
    @SerializedName("complaint_hours") val complaintHours: Int = 24,
)

data class ConsultCancelDto(val by: String? = null, val reason: String? = null, val late: Boolean = false)

data class ConsultComplaintDto(val text: String? = null)

data class ConsultResolutionDto(val decision: String? = null, val note: String? = null)

data class ConsultCanDto(
    val cancel: Boolean = false,
    @SerializedName("cancel_free") val cancelFree: Boolean = false,
    val confirm: Boolean = false,
    val complain: Boolean = false,
)

// ---------------------------------------------------------------- FL-L16: the video session

/** The session as one side sees it: the room's hours, who is in, who came when, and the recorded parts. */
data class ConsultSessionDto(
    /** LiveKit is set up on the server. */
    val available: Boolean = false,
    @SerializedName("room_open") val roomOpen: Boolean = false,
    @SerializedName("opens_at") val opensAt: String? = null,
    @SerializedName("starts_at") val startsAt: String? = null,
    @SerializedName("ends_at") val endsAt: String? = null,
    /** by = time · staff */
    val closed: ConsultSessionClosedDto? = null,
    val recorded: Boolean = true,
    /** The recording bucket is set up (without it the session runs unrecorded). */
    @SerializedName("recording_available") val recordingAvailable: Boolean = false,
    @SerializedName("in_room") val inRoom: ConsultSidesDto = ConsultSidesDto(),
    val attendance: List<ConsultAttendanceDto> = emptyList(),
    val recordings: List<ConsultRecordingDto> = emptyList(),
    @SerializedName("recordings_delete_at") val recordingsDeleteAt: String? = null,
    @SerializedName("recordings_held") val recordingsHeld: Boolean = false,
    @SerializedName("recordings_deleted_at") val recordingsDeletedAt: String? = null,
    val can: ConsultSessionCanDto = ConsultSessionCanDto(),
)

data class ConsultSessionClosedDto(val by: String? = null, val at: String? = null)

data class ConsultSidesDto(val client: Boolean = false, val freelancer: Boolean = false)

data class ConsultAttendanceDto(
    /** client · freelancer */
    val side: String = "",
    @SerializedName("joined_at") val joinedAt: String? = null,
    @SerializedName("left_at") val leftAt: String? = null,
)

data class ConsultRecordingDto(
    val uuid: String = "",
    val part: Int = 1,
    /** recording · processing · moving · stored · deleted */
    val status: String = "",
    @SerializedName("duration_seconds") val durationSeconds: Long? = null,
    @SerializedName("size_bytes") val sizeBytes: Long? = null,
    @SerializedName("started_at") val startedAt: String? = null,
    @SerializedName("stopped_at") val stoppedAt: String? = null,
    /** Signed for me, good for 15 minutes; only while `stored`. */
    @SerializedName("download_url") val downloadUrl: String? = null,
    @SerializedName("watch_url") val watchUrl: String? = null,
)

data class ConsultSessionCanDto(
    val join: Boolean = false,
    @SerializedName("no_show") val noShow: Boolean = false,
)

/** A ticket into the room: LiveKit's address and a token good until the end, the hours on the server's clock, and the other side. */
data class ConsultJoinDto(
    val url: String = "",
    val token: String = "",
    val room: String? = null,
    val identity: String? = null,
    @SerializedName("starts_at") val startsAt: String? = null,
    @SerializedName("ends_at") val endsAt: String? = null,
    @SerializedName("server_time") val serverTime: String? = null,
    @SerializedName("warn_before_end_minutes") val warnBeforeEndMinutes: Int = 5,
    val recording: Boolean = false,
    val other: ConsultJoinOtherDto = ConsultJoinOtherDto(),
)

data class ConsultJoinOtherDto(
    val side: String? = null,
    val name: String? = null,
    @SerializedName("in_room") val inRoom: Boolean = false,
)
