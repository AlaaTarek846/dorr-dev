package com.dorr.freelance.net

import com.dorr.core.network.ApiEnvelope
import com.google.gson.JsonObject
import com.google.gson.annotations.SerializedName
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.DELETE
import retrofit2.http.GET
import retrofit2.http.HTTP
import retrofit2.http.POST
import retrofit2.http.Path
import retrofit2.http.Query

/** The freelancer's notifications and how they reach the phone (FL-007), under `/api/freelance/v1`. */
interface NotificationsApi {
    /** The settings of the live connection and the push app, served by the API so moving servers needs no app update. */
    @GET("${ApiClient.FREELANCE}realtime-config")
    suspend fun realtimeConfig(): Response<ApiEnvelope<RealtimeConfigDto>>

    @GET("${ApiClient.FREELANCE}notifications")
    suspend fun list(@Query("per_page") perPage: Int = 40, @Query("page") page: Int = 1): Response<ApiEnvelope<List<NotificationDto>>>

    @GET("${ApiClient.FREELANCE}notifications/unread-count")
    suspend fun unreadCount(): Response<ApiEnvelope<UnreadCountDto>>

    @POST("${ApiClient.FREELANCE}notifications/{id}/read")
    suspend fun markRead(@Path("id") id: String): Response<ApiEnvelope<UnreadCountDto>>

    @POST("${ApiClient.FREELANCE}notifications/read-all")
    suspend fun markAllRead(): Response<ApiEnvelope<UnreadCountDto>>

    /** This phone's push id, so pushes reach it (asked on every open: the id can move between accounts). */
    @POST("${ApiClient.FREELANCE}notifications/devices")
    suspend fun registerDevice(@Body body: RegisterDeviceRequest): Response<ApiEnvelope<Any>>

    @HTTP(method = "DELETE", path = "${ApiClient.FREELANCE}notifications/devices", hasBody = true)
    suspend fun unregisterDevice(@Body body: RegisterDeviceRequest): Response<ApiEnvelope<Any>>
}

data class RealtimeConfigDto(
    val enabled: Boolean = false,
    val key: String? = null,
    val cluster: String? = null,
    val host: String? = null,
    val port: Int? = null,
    @SerializedName("use_tls") val useTls: Boolean = true,
    @SerializedName("auth_path") val authPath: String = "/broadcasting/auth",
    /** The freelancer's own private channel. */
    val channel: String? = null,
    @SerializedName("onesignal_app_id") val oneSignalAppId: String? = null,
)

data class NotificationDto(
    val id: String,
    val title: String = "",
    val message: String = "",
    val type: String? = null,
    /** e.g. `freelance.verification.approved` — what the app picks the icon and the screen from. */
    val event: String? = null,
    val data: JsonObject? = null,
    @SerializedName("created_at_iso") val createdAtIso: String? = null,
    @SerializedName("read_at") val readAt: String? = null,
)

data class UnreadCountDto(val count: Int = 0)

data class RegisterDeviceRequest(
    @SerializedName("player_id") val playerId: String,
    val platform: String = "android",
)
