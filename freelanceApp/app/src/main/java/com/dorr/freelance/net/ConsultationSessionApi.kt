package com.dorr.freelance.net

import com.dorr.core.network.ApiEnvelope
import com.google.gson.annotations.SerializedName
import retrofit2.Response
import retrofit2.http.GET
import retrofit2.http.POST
import retrofit2.http.Path
import java.time.Instant
import java.time.OffsetDateTime

/**
 * FL-L16, the consultation's video session under `/api/freelance/v1/consultations/{uuid}/session…`: the room's state (also inside the
 * consultation as `session`), a ticket into the LiveKit room, and saying the app is in it / left it. No polling: the end is known from
 * `ends_at` on the phone's clock (corrected by the server's), and the notices say when the other side came, the end is near, or the
 * recording is ready.
 */
interface ConsultationSessionApi {
    @GET("${ApiClient.FREELANCE}consultations/{uuid}/session")
    suspend fun session(@Path("uuid") uuid: String): Response<ApiEnvelope<ConsultationSessionDto>>

    /** A ticket into the room (LiveKit's address and a token good until the end). */
    @POST("${ApiClient.FREELANCE}consultations/{uuid}/session/join")
    suspend fun join(@Path("uuid") uuid: String): Response<ApiEnvelope<ConsultationJoinDto>>

    /** The app is in the room now: my stay starts (and the recording, if it is not running). */
    @POST("${ApiClient.FREELANCE}consultations/{uuid}/session/connected")
    suspend fun connected(@Path("uuid") uuid: String): Response<ApiEnvelope<ConsultationSessionDto>>

    /** The app left the room; coming back while time is left is fine. */
    @POST("${ApiClient.FREELANCE}consultations/{uuid}/session/leave")
    suspend fun leave(@Path("uuid") uuid: String): Response<ApiEnvelope<ConsultationSessionDto>>
}

/** What the consultation screen shows about the session (ConsultationSessions::presentFor). */
data class ConsultationSessionDto(
    /** LiveKit is set up on the server. */
    val available: Boolean = false,
    @SerializedName("room_open") val roomOpen: Boolean = false,
    @SerializedName("opens_at") val opensAt: String? = null,
    @SerializedName("starts_at") val startsAt: String? = null,
    @SerializedName("ends_at") val endsAt: String? = null,
    /** `by`: time · staff */
    val closed: SessionClosedDto? = null,
    val recorded: Boolean = true,
    /** The recording can really be made (the bucket is set up); until then sessions run unrecorded. */
    @SerializedName("recording_available") val recordingAvailable: Boolean = false,
    val consent: SessionConsentDto = SessionConsentDto(),
    @SerializedName("in_room") val inRoom: SessionInRoomDto = SessionInRoomDto(),
    val attendance: List<SessionStayDto> = emptyList(),
    val recordings: List<SessionRecordingDto> = emptyList(),
    @SerializedName("recordings_delete_at") val recordingsDeleteAt: String? = null,
    /** Kept until the staff decide on a complaint. */
    @SerializedName("recordings_held") val recordingsHeld: Boolean = false,
    @SerializedName("recordings_deleted_at") val recordingsDeletedAt: String? = null,
    val can: SessionCanDto = SessionCanDto(),
)

data class SessionClosedDto(val by: String? = null, val at: String? = null)

data class SessionConsentDto(val client: String? = null, val freelancer: String? = null)

data class SessionInRoomDto(val client: Boolean = false, val freelancer: Boolean = false)

/** One stay in the room: `side` client · freelancer. */
data class SessionStayDto(
    val side: String,
    @SerializedName("joined_at") val joinedAt: String? = null,
    @SerializedName("left_at") val leftAt: String? = null,
)

/** One recorded part. `status`: recording · processing · moving · stored · deleted. The links are signed for a few minutes. */
data class SessionRecordingDto(
    val uuid: String,
    val part: Int = 1,
    val status: String = "processing",
    @SerializedName("duration_seconds") val durationSeconds: Int? = null,
    @SerializedName("size_bytes") val sizeBytes: Long? = null,
    @SerializedName("started_at") val startedAt: String? = null,
    @SerializedName("stopped_at") val stoppedAt: String? = null,
    @SerializedName("download_url") val downloadUrl: String? = null,
    @SerializedName("watch_url") val watchUrl: String? = null,
)

data class SessionCanDto(val join: Boolean = false)

/** The ticket into the room. */
data class ConsultationJoinDto(
    val url: String,
    val token: String,
    val room: String? = null,
    val identity: String? = null,
    @SerializedName("starts_at") val startsAt: String? = null,
    @SerializedName("ends_at") val endsAt: String,
    @SerializedName("server_time") val serverTime: String? = null,
    @SerializedName("warn_before_end_minutes") val warnBeforeEndMinutes: Int = 5,
    /** The session is being recorded. */
    val recording: Boolean = false,
    val other: SessionOtherDto? = null,
)

data class SessionOtherDto(val side: String? = null, val name: String? = null, @SerializedName("in_room") val inRoom: Boolean = false)

/**
 * The server's clock as seen from the phone: the phone's own clock plus a correction taken once — from the first answer's `Date`
 * header, and precisely from the join ticket's `server_time`. Countdowns read [now]; nothing asks the server on a timer.
 */
object ServerClock {
    @Volatile
    private var offsetMs: Long = 0L

    @Volatile
    private var precise = false

    @Volatile
    private var seen = false

    /** The `Date` header of an answer (seconds only): taken once, and never over a precise value. */
    fun observeHeader(serverMs: Long) {
        if (seen || precise) return
        seen = true
        offsetMs = serverMs - System.currentTimeMillis()
    }

    /** The join ticket's `server_time`. */
    fun observe(iso: String?) {
        val at = parseInstant(iso) ?: return
        offsetMs = at.toEpochMilli() - System.currentTimeMillis()
        precise = true
        seen = true
    }

    fun nowMs(): Long = System.currentTimeMillis() + offsetMs

    fun now(): Instant = Instant.ofEpochMilli(nowMs())

    fun parseInstant(iso: String?): Instant? =
        iso?.let { runCatching { Instant.parse(it) }.getOrNull() ?: runCatching { OffsetDateTime.parse(it).toInstant() }.getOrNull() }
}
