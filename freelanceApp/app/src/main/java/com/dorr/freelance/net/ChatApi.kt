package com.dorr.freelance.net

import com.dorr.core.network.ApiEnvelope
import com.google.gson.annotations.SerializedName
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.POST
import retrofit2.http.Path
import retrofit2.http.Query

/**
 * The talk with a client about an offer (FL-308), on the client-freelancer chat of FL-006 under `/api/freelance/v1/chat`. The client opens
 * the room from their side; I find it on my offer (`chat.uuid`) and answer in it. New messages arrive live (one reload per notice).
 */
interface ChatApi {
    @GET("${ApiClient.FREELANCE}chat/conversations/{uuid}")
    suspend fun room(@Path("uuid") uuid: String): Response<ApiEnvelope<ChatRoomDto>>

    /** Newest first; `before` is the id to continue from. */
    @GET("${ApiClient.FREELANCE}chat/conversations/{uuid}/messages")
    suspend fun messages(@Path("uuid") uuid: String, @Query("limit") limit: Int = 40, @Query("before") before: Int? = null): Response<ApiEnvelope<List<ChatMessageDto>>>

    @POST("${ApiClient.FREELANCE}chat/conversations/{uuid}/messages")
    suspend fun send(@Path("uuid") uuid: String, @Body body: ChatSendRequest): Response<ApiEnvelope<ChatMessageDto>>

    /** FL-310: the interviews of this room; propose a time; confirm / decline / cancel one. */
    @GET("${ApiClient.FREELANCE}chat/conversations/{uuid}/interviews")
    suspend fun interviews(@Path("uuid") uuid: String): Response<ApiEnvelope<List<InterviewDto>>>

    @POST("${ApiClient.FREELANCE}chat/conversations/{uuid}/interviews")
    suspend fun proposeInterview(@Path("uuid") uuid: String, @Body body: InterviewProposeRequest): Response<ApiEnvelope<List<InterviewDto>>>

    @POST("${ApiClient.FREELANCE}chat/conversations/{uuid}/interviews/{interview}/{action}")
    suspend fun answerInterview(@Path("uuid") uuid: String, @Path("interview") interview: String, @Path("action") action: String): Response<ApiEnvelope<List<InterviewDto>>>

    @POST("${ApiClient.FREELANCE}chat/conversations/{uuid}/read")
    suspend fun read(@Path("uuid") uuid: String): Response<ApiEnvelope<Map<String, Int>>>
}

data class ChatRoomDto(
    val uuid: String,
    val status: String = "open",
    @SerializedName("can_write") val canWrite: Boolean = false,
    /** Before the agreement phone numbers, emails and links are not allowed. */
    val guarded: Boolean = false,
    val peer: ChatPeerDto = ChatPeerDto(),
    val project: ChatProjectDto? = null,
    val unread: Int = 0,
)

data class ChatPeerDto(val name: String? = null)

data class ChatProjectDto(val uuid: String? = null, val title: String? = null)

data class ChatMessageDto(
    val id: Int,
    val mine: Boolean? = null,
    val kind: String = "text",
    val body: String? = null,
    @SerializedName("created_at") val createdAt: String? = null,
    @SerializedName("read_at") val readAt: String? = null,
)

data class ChatSendRequest(val body: String, @SerializedName("client_id") val clientId: String)

/** What my offer shows of the room (null: the client has not opened one). */
data class OfferChatDto(val uuid: String, val unread: Int = 0, @SerializedName("can_write") val canWrite: Boolean = false)

data class InterviewDto(
    val uuid: String = "",
    /** proposed, confirmed, declined, cancelled or superseded. */
    val status: String = "proposed",
    @SerializedName("proposed_by") val proposedBy: String = "client",
    @SerializedName("starts_at") val startsAt: String = "",
    @SerializedName("duration_minutes") val durationMinutes: Int = 15,
    val note: String? = null,
)

data class InterviewProposeRequest(
    @SerializedName("starts_at") val startsAt: String,
    @SerializedName("duration_minutes") val durationMinutes: Int = 15,
    val note: String? = null,
)
