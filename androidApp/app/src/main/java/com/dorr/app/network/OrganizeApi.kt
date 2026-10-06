package com.dorr.app.network

import com.google.gson.JsonElement
import com.google.gson.JsonObject
import com.google.gson.annotations.SerializedName
import okhttp3.MultipartBody
import okhttp3.RequestBody
import retrofit2.http.Body
import retrofit2.http.DELETE
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.Multipart
import retrofit2.http.PATCH
import retrofit2.http.POST
import retrofit2.http.PUT
import retrofit2.http.Part
import retrofit2.http.PartMap
import retrofit2.http.Path
import retrofit2.http.Query

/**
 * Organising chats: privacy circles (spec 98–103), threads (122), group decisions (119–120) and
 * broadcast lists (like WhatsApp's).
 */
interface OrganizeApi {
    // ------------------------------------------------------------------ circles

    @GET("mobile/v1/chat/circles")
    suspend fun circles(@Header("Authorization") auth: String): ApiEnvelope<List<CircleDto>>

    @POST("mobile/v1/chat/circles")
    suspend fun createCircle(@Header("Authorization") auth: String, @Body body: JsonObject): ApiEnvelope<CircleDto>

    @PATCH("mobile/v1/chat/circles/{id}")
    suspend fun updateCircle(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: JsonObject): ApiEnvelope<CircleDto>

    @DELETE("mobile/v1/chat/circles/{id}")
    suspend fun deleteCircle(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<JsonElement?>

    /** `{circle_id}` — null takes the chat out of its circle. */
    @PUT("mobile/v1/chat/conversations/{id}/circle")
    suspend fun setCircle(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: JsonObject): ApiEnvelope<ConversationDto>

    // ------------------------------------------------------------------ threads

    @GET("mobile/v1/chat/conversations/{id}/messages")
    suspend fun thread(
        @Header("Authorization") auth: String,
        @Path("id") id: String,
        @Query("thread") root: String,
        @Query("limit") limit: Int = 100,
    ): ApiEnvelope<ThreadPageDto>

    // ------------------------------------------------------------------ decisions

    @POST("mobile/v1/chat/messages/{id}/decision")
    suspend fun makeDecision(@Header("Authorization") auth: String, @Path("id") messageId: String, @Body body: JsonObject): ApiEnvelope<DecisionDto>

    @POST("mobile/v1/chat/decisions/{id}/decide")
    suspend fun decide(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: JsonObject): ApiEnvelope<DecisionDto>

    @GET("mobile/v1/chat/groups/{id}/decisions")
    suspend fun decisions(@Header("Authorization") auth: String, @Path("id") conversationId: String, @Query("status") status: String? = null): ApiEnvelope<List<DecisionDto>>

    // ------------------------------------------------------------------ AI about a chat (spec 125–126)

    /** `{question, messages?[]}` — only the picked messages (or the latest) go to the AI. */
    @POST("mobile/v1/chat/conversations/{id}/ask")
    suspend fun ask(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: JsonObject): ApiEnvelope<AskAnswerDto>

    /** `{messages?[], timezone}` — suggestions only. */
    @POST("mobile/v1/chat/conversations/{id}/commitments")
    suspend fun commitments(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: JsonObject): ApiEnvelope<CommitmentsDto>

    // ------------------------------------------------------------------ broadcasts

    @GET("mobile/v1/chat/broadcasts")
    suspend fun broadcasts(@Header("Authorization") auth: String): ApiEnvelope<List<BroadcastDto>>

    @POST("mobile/v1/chat/broadcasts")
    suspend fun createBroadcast(@Header("Authorization") auth: String, @Body body: JsonObject): ApiEnvelope<BroadcastDto>

    @GET("mobile/v1/chat/broadcasts/{id}")
    suspend fun broadcast(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<BroadcastDto>

    @PATCH("mobile/v1/chat/broadcasts/{id}")
    suspend fun updateBroadcast(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: JsonObject): ApiEnvelope<BroadcastDto>

    @DELETE("mobile/v1/chat/broadcasts/{id}")
    suspend fun deleteBroadcast(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<JsonElement?>

    @Multipart
    @POST("mobile/v1/chat/broadcasts/{id}/messages")
    suspend fun sendBroadcast(
        @Header("Authorization") auth: String,
        @Path("id") id: String,
        @PartMap fields: Map<String, @JvmSuppressWildcards RequestBody>,
        @Part files: List<MultipartBody.Part>,
    ): ApiEnvelope<BroadcastResultDto>
}

data class CircleDto(
    val id: String,
    val name: String,
    @SerializedName("masked_name") val maskedName: String? = null,
    @SerializedName("shown_name") val shownName: String,
    val emoji: String? = null,
    val color: String? = null,
    /** all · name · circle · none · hidden (P0 … P4) */
    val disclosure: String = "circle",
    @SerializedName("hide_from_list") val hideFromList: Boolean = true,
    val locked: Boolean = false,
    @SerializedName("chats_count") val chatsCount: Int = 0,
    val unread: Int = 0,
)

data class ThreadPageDto(val root: MessageDto?, val messages: List<MessageDto> = emptyList())

data class DecisionOptionDto(val id: Int, val text: String, val votes: Int = 0)

data class DecisionDto(
    val id: String,
    val title: String,
    /** open · approved · rejected */
    val status: String,
    val outcome: String? = null,
    @SerializedName("source_message_id") val sourceMessageId: String? = null,
    @SerializedName("poll_message_id") val pollMessageId: String? = null,
    val options: List<DecisionOptionDto> = emptyList(),
    val voters: Int = 0,
    @SerializedName("created_by") val createdBy: ProfileDto? = null,
    @SerializedName("decided_by") val decidedBy: ProfileDto? = null,
    @SerializedName("created_at") val createdAt: String? = null,
    @SerializedName("decided_at") val decidedAt: String? = null,
)

data class BroadcastSentDto(
    val id: Int,
    val type: String,
    val body: String? = null,
    val thumbnail: String? = null,
    @SerializedName("sent_count") val sentCount: Int = 0,
    @SerializedName("skipped_count") val skippedCount: Int = 0,
    @SerializedName("created_at") val createdAt: String? = null,
)

data class BroadcastLastDto(val body: String? = null, val type: String? = null, val at: String? = null)

data class BroadcastDto(
    val id: String,
    val name: String? = null,
    val title: String,
    @SerializedName("members_count") val membersCount: Int = 0,
    @SerializedName("last_sent") val lastSent: BroadcastLastDto? = null,
    val members: List<ProfileDto>? = null,
    val sent: List<BroadcastSentDto>? = null,
)

data class BroadcastResultDto(val sent: Int, val skipped: List<ProfileDto> = emptyList(), val broadcast: BroadcastSentDto? = null)

data class AskReferenceDto(val id: String, val sender: String? = null, val excerpt: String = "", @SerializedName("created_at") val createdAt: String? = null)

data class AskAnswerDto(val answer: String, val references: List<AskReferenceDto> = emptyList(), val messages: Int = 0, val safety: SafetyDto? = null)

data class CommitmentDto(
    val text: String,
    /** The other person's name (null when it's mine). */
    val owner: String? = null,
    @SerializedName("is_mine") val isMine: Boolean = false,
    @SerializedName("due_at") val dueAt: String? = null,
    @SerializedName("message_id") val messageId: String? = null,
)

data class CommitmentsDto(val commitments: List<CommitmentDto> = emptyList(), val messages: Int = 0)
