package com.dorr.app.network

import com.google.gson.JsonElement
import com.google.gson.JsonObject
import com.google.gson.annotations.SerializedName
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.PATCH
import retrofit2.http.POST
import retrofit2.http.Path

/**
 * Standing voice rooms (spec 91): every group and channel has one. Join gives a LiveKit token
 * (a listener's can't publish); hosts change who speaks live. `chat.voice.updated` tells the chat
 * who's in it.
 */
interface VoiceApi {
    @GET("mobile/v1/chat/conversations/{id}/voice-room")
    suspend fun room(@Header("Authorization") auth: String, @Path("id") conversationId: String): ApiEnvelope<VoiceRoomDto>

    @POST("mobile/v1/chat/conversations/{id}/voice-room/join")
    suspend fun join(@Header("Authorization") auth: String, @Path("id") conversationId: String): ApiEnvelope<VoiceJoinDto>

    @POST("mobile/v1/chat/voice-rooms/{id}/leave")
    suspend fun leave(@Header("Authorization") auth: String, @Path("id") roomId: String): ApiEnvelope<JsonElement?>

    @POST("mobile/v1/chat/voice-rooms/{id}/ping")
    suspend fun ping(@Header("Authorization") auth: String, @Path("id") roomId: String): ApiEnvelope<JsonElement?>

    /** `{raised}` */
    @POST("mobile/v1/chat/voice-rooms/{id}/hand")
    suspend fun hand(@Header("Authorization") auth: String, @Path("id") roomId: String, @Body body: JsonObject): ApiEnvelope<VoiceRoomDto>

    /** `{role: speaker | listener}` — hosts */
    @POST("mobile/v1/chat/voice-rooms/{id}/members/{participant}/role")
    suspend fun role(@Header("Authorization") auth: String, @Path("id") roomId: String, @Path("participant") participantId: Int, @Body body: JsonObject): ApiEnvelope<VoiceRoomDto>

    @POST("mobile/v1/chat/voice-rooms/{id}/members/{participant}/remove")
    suspend fun remove(@Header("Authorization") auth: String, @Path("id") roomId: String, @Path("participant") participantId: Int): ApiEnvelope<VoiceRoomDto>

    /** `{speak_policy?: everyone | approved, title?}` — hosts */
    @PATCH("mobile/v1/chat/voice-rooms/{id}")
    suspend fun update(@Header("Authorization") auth: String, @Path("id") roomId: String, @Body body: JsonObject): ApiEnvelope<VoiceRoomDto>

    // ------------------------------------------------------------------ a call broadcast live (spec 93)

    /** `{title?, audience: members | link}` — someone in the call (a group's admin) */
    @POST("mobile/v1/chat/calls/{id}/live")
    suspend fun startLive(@Header("Authorization") auth: String, @Path("id") callId: String, @Body body: JsonObject): ApiEnvelope<LiveDto>

    @POST("mobile/v1/chat/lives/{id}/end")
    suspend fun endLive(@Header("Authorization") auth: String, @Path("id") liveId: String): ApiEnvelope<LiveDto>

    @POST("mobile/v1/chat/lives/watch/{token}")
    suspend fun watch(@Header("Authorization") auth: String, @Path("token") token: String): ApiEnvelope<LiveWatchDto>

    @POST("mobile/v1/chat/lives/watch/{token}/ping")
    suspend fun pingLive(@Header("Authorization") auth: String, @Path("token") token: String): ApiEnvelope<LiveDto>

    /** `{conversation_ids[]}` */
    @POST("mobile/v1/chat/lives/watch/{token}/share")
    suspend fun shareLive(@Header("Authorization") auth: String, @Path("token") token: String, @Body body: JsonObject): ApiEnvelope<JsonObject>
}

data class LiveHostDto(val name: String? = null, val avatar: String? = null)

/** `status`: live · ended. `audience`: members · link. */
data class LiveDto(
    val id: String = "",
    val token: String = "",
    val link: String? = null,
    val title: String? = null,
    val audience: String = "members",
    val status: String = "live",
    val host: LiveHostDto? = null,
    val viewers: Int = 0,
)

data class LiveWatchDto(val live: LiveDto, val join: JoinDto? = null)

data class VoiceMemberDto(
    @SerializedName("participant_id") val participantId: Int = 0,
    val key: String = "",
    val name: String? = null,
    val avatar: String? = null,
    /** host · speaker · listener */
    val role: String = "speaker",
    @SerializedName("hand_raised") val handRaised: Boolean = false,
)

data class VoiceMeDto(@SerializedName("in_room") val inRoom: Boolean = false, val role: String = "speaker", @SerializedName("can_manage") val canManage: Boolean = false)

data class VoiceRoomDto(
    val id: String = "",
    @SerializedName("conversation_id") val conversationId: String = "",
    val title: String? = null,
    /** everyone · approved */
    @SerializedName("speak_policy") val speakPolicy: String = "everyone",
    val count: Int = 0,
    val members: List<VoiceMemberDto> = emptyList(),
    val me: VoiceMeDto = VoiceMeDto(),
)

data class VoiceJoinDto(val room: VoiceRoomDto, val join: JoinDto)
