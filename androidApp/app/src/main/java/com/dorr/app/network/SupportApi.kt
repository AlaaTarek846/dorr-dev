package com.dorr.app.network

import com.google.gson.annotations.SerializedName
import okhttp3.MultipartBody
import okhttp3.RequestBody
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.Multipart
import retrofit2.http.POST
import retrofit2.http.Part
import retrofit2.http.Query

/**
 * Support tickets under /api/mobile/v1/support-tickets.
 * List is scoped to the bearer token; POST opens a new ticket.
 */
interface SupportApi {
    @GET("mobile/v1/support-tickets")
    suspend fun listTickets(
        @Header("Authorization") authorization: String,
        @Query("page") page: Int = 1,
        @Query("per_page") perPage: Int = 20,
    ): ApiEnvelope<List<SupportTicketDto>>

    @Multipart
    @POST("mobile/v1/support-tickets")
    suspend fun createTicket(
        @Header("Authorization") authorization: String,
        @Part("title") title: RequestBody,
        @Part("body") body: RequestBody,
        @Part image: MultipartBody.Part?,
    ): ApiEnvelope<SupportTicketDto>

    @GET("mobile/v1/support-chats")
    suspend fun listMessages(
        @Header("Authorization") authorization: String,
        @Query("ticket_id") ticketId: Int? = null,
        @Query("all") all: Int = 1,
    ): ApiEnvelope<List<SupportMessageDto>>

    @POST("mobile/v1/support-chats")
    suspend fun sendMessage(
        @Header("Authorization") authorization: String,
        @Body body: SendSupportMessageRequest,
    ): ApiEnvelope<SupportMessageDto>
}

data class SupportTicketDto(
    val id: Int,
    val title: String?,
    val body: String? = null,
    val status: String?,
    @SerializedName("created_at") val createdAt: String? = null,
)

data class SendSupportMessageRequest(
    val body: String,
    @SerializedName("ticket_id") val ticketId: Int? = null,
)

data class SupportMessageDto(
    val id: Int,
    @SerializedName("ticket_id") val ticketId: Int? = null,
    val sender: String?,
    val body: String?,
    @SerializedName("created_at") val createdAt: String? = null,
)
