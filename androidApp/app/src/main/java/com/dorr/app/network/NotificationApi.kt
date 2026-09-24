package com.dorr.app.network

import com.google.gson.JsonObject
import com.google.gson.annotations.SerializedName
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.POST
import retrofit2.http.Path
import retrofit2.http.Query

/**
 * The account's own notifications under /api/mobile/v1/notifications. The server sends each row
 * already in the language the app asked for (`X-Locale`), so the list is simply reloaded after a
 * language change.
 */
interface NotificationApi {
    @GET("mobile/v1/notifications")
    suspend fun list(
        @Header("Authorization") authorization: String,
        @Query("per_page") perPage: Int = 40,
    ): ApiEnvelope<List<NotificationDto>>

    @GET("mobile/v1/notifications/unread-count")
    suspend fun unreadCount(@Header("Authorization") authorization: String): ApiEnvelope<UnreadCountDto>

    @POST("mobile/v1/notifications/{id}/read")
    suspend fun markRead(
        @Header("Authorization") authorization: String,
        @Path("id") id: String,
    ): ApiEnvelope<UnreadCountDto>

    @POST("mobile/v1/notifications/read-all")
    suspend fun markAllRead(@Header("Authorization") authorization: String): ApiEnvelope<UnreadCountDto>

    /** Tells the server which OneSignal player id belongs to this phone, so pushes reach it. */
    @POST("mobile/v1/notifications/devices")
    suspend fun registerDevice(
        @Header("Authorization") authorization: String,
        @Body body: RegisterDeviceRequest,
    ): ApiEnvelope<Any?>
}

data class NotificationDto(
    val id: String,
    val title: String,
    val message: String,
    /** e.g. "wallet.transfer.received" — what the app keys its icon and deep link on. */
    val event: String? = null,
    val data: JsonObject? = null,
    @SerializedName("created_at_iso") val createdAtIso: String? = null,
    @SerializedName("read_at") val readAt: String? = null,
)

data class UnreadCountDto(val count: Int)

data class RegisterDeviceRequest(
    @SerializedName("player_id") val playerId: String,
    val platform: String = "android",
)
