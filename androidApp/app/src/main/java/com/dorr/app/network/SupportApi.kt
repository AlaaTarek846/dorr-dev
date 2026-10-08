package com.dorr.app.network

import com.google.gson.annotations.SerializedName
import okhttp3.MultipartBody
import okhttp3.RequestBody
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.Multipart
import retrofit2.http.PATCH
import retrofit2.http.POST
import retrofit2.http.Part
import retrofit2.http.Path
import retrofit2.http.Query

/**
 * Support tickets under /api/mobile/v1/support-tickets: the list, opening one, the conversation
 * inside it and closing / reopening it. Everything is scoped to the bearer token. Live changes
 * arrive on the account's Pusher channel (`support.message`, `support.ticket.updated`), see
 * [com.dorr.app.chat.ChatRealtime].
 */
interface SupportApi {
    @GET("mobile/v1/support-tickets")
    suspend fun listTickets(
        @Header("Authorization") authorization: String,
        @Query("page") page: Int = 1,
        @Query("per_page") perPage: Int = 20,
    ): ApiEnvelope<List<SupportTicketDto>>

    @GET("mobile/v1/support-tickets/{id}")
    suspend fun ticket(
        @Header("Authorization") authorization: String,
        @Path("id") id: Int,
    ): ApiEnvelope<SupportTicketDto>

    @Multipart
    @POST("mobile/v1/support-tickets")
    suspend fun createTicket(
        @Header("Authorization") authorization: String,
        @Part("title") title: RequestBody,
        @Part("body") body: RequestBody,
        @Part image: MultipartBody.Part?,
    ): ApiEnvelope<SupportTicketDto>

    @PATCH("mobile/v1/support-tickets/{id}/status")
    suspend fun setStatus(
        @Header("Authorization") authorization: String,
        @Path("id") id: Int,
        @Body body: SupportStatusRequest,
    ): ApiEnvelope<SupportTicketDto>

    @POST("mobile/v1/support-tickets/{id}/auto-reply-feedback")
    suspend fun autoReplyFeedback(
        @Header("Authorization") authorization: String,
        @Path("id") id: Int,
        @Body body: SupportAutoReplyFeedbackRequest,
    ): ApiEnvelope<SupportTicketDto>

    /** Newest page first (`order=desc`): a long conversation opens at its end, older pages follow. */
    @GET("mobile/v1/support-tickets/{id}/messages")
    suspend fun messages(
        @Header("Authorization") authorization: String,
        @Path("id") id: Int,
        @Query("page") page: Int = 1,
        @Query("per_page") perPage: Int = 30,
        @Query("order") order: String = "desc",
    ): ApiEnvelope<List<SupportMessageDto>>

    @Multipart
    @POST("mobile/v1/support-tickets/{id}/messages")
    suspend fun sendMessage(
        @Header("Authorization") authorization: String,
        @Path("id") id: Int,
        @Part("body") body: RequestBody?,
        @Part image: MultipartBody.Part?,
    ): ApiEnvelope<SupportMessageDto>
}

data class SupportLastMessageDto(
    val sender: String? = null,
    val body: String? = null,
    @SerializedName("has_image") val hasImage: Boolean = false,
    @SerializedName("created_at") val createdAt: String? = null,
)

data class SupportTicketDto(
    val id: Int,
    val title: String?,
    val body: String? = null,
    @SerializedName("image_url") val imageUrl: String? = null,
    /** `opened`, `reopened`, `resolved` or `closed`. */
    val status: String?,
    @SerializedName("accepts_replies") val acceptsReplies: Boolean = true,
    /** The customer said an automatic answer was not enough: no more automatic replies. */
    @SerializedName("auto_reply_stopped") val autoReplyStopped: Boolean = false,
    @SerializedName("last_message") val lastMessage: SupportLastMessageDto? = null,
    @SerializedName("last_message_at") val lastMessageAt: String? = null,
    @SerializedName("created_at") val createdAt: String? = null,
)

data class SupportMessageDto(
    val id: Int,
    @SerializedName("ticket_id") val ticketId: Int? = null,
    /** `user` (me), `support` or `system` (an automatic reply). */
    val sender: String? = null,
    val body: String? = null,
    @SerializedName("image_url") val imageUrl: String? = null,
    @SerializedName("agent_name") val agentName: String? = null,
    @SerializedName("is_auto") val isAuto: Boolean = false,
    /** `ack`, `away` or `faq`. */
    @SerializedName("auto_kind") val autoKind: String? = null,
    @SerializedName("created_at") val createdAt: String? = null,
)

data class SupportStatusRequest(val status: String)
data class SupportAutoReplyFeedbackRequest(val solved: Boolean)
