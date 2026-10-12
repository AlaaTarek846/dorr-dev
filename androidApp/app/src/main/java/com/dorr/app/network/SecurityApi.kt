package com.dorr.app.network

import com.google.gson.annotations.SerializedName
import retrofit2.http.DELETE
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.POST
import retrofit2.http.Path
import retrofit2.http.Query

/**
 * Account security under /api/mobile/v1/security (Modules\User Mobile\SecurityController): the devices this account
 * is signed in on — any of them can be signed out, or all but this one — and the account's security log.
 */
interface SecurityApi {
    @GET("mobile/v1/security/devices")
    suspend fun devices(
        @Header("Authorization") authorization: String,
    ): ApiEnvelope<List<SecurityDeviceDto>>

    @DELETE("mobile/v1/security/devices/{id}")
    suspend fun signOut(
        @Header("Authorization") authorization: String,
        @Path("id") id: Long,
    ): ApiEnvelope<SignOutResultDto>

    @POST("mobile/v1/security/devices/logout-others")
    suspend fun signOutOthers(
        @Header("Authorization") authorization: String,
    ): ApiEnvelope<SignOutResultDto>

    @GET("mobile/v1/security/events")
    suspend fun events(
        @Header("Authorization") authorization: String,
        @Query("page") page: Int = 1,
        @Query("warnings") warnings: Int? = null,
    ): ApiEnvelope<List<SecurityEventDto>>
}

data class SecurityDeviceDto(
    val id: Long,
    @SerializedName("device_name") val deviceName: String?,
    val platform: String?,
    @SerializedName("platform_label") val platformLabel: String?,
    @SerializedName("app_version") val appVersion: String?,
    val ip: String?,
    @SerializedName("country_code") val countryCode: String?,
    val current: Boolean,
    @SerializedName("signed_in_at") val signedInAt: String?,
    @SerializedName("last_active_at") val lastActiveAt: String?,
)

data class SignOutResultDto(
    @SerializedName("signed_out_current") val signedOutCurrent: Boolean? = null,
    val count: Int? = null,
    val devices: List<SecurityDeviceDto>? = null,
)

data class SecurityEventDto(
    val id: Long,
    val type: String,
    val label: String,
    val description: String?,
    val warning: Boolean,
    @SerializedName("device_name") val deviceName: String?,
    @SerializedName("platform_label") val platformLabel: String?,
    val ip: String?,
    @SerializedName("country_code") val countryCode: String?,
    @SerializedName("created_at") val createdAt: String?,
)
