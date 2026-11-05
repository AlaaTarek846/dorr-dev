package com.dorr.provider.net

import com.dorr.core.network.ApiEnvelope
import com.google.gson.annotations.SerializedName
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.POST

/**
 * The provider's sign-in (docs/moving_tasks.md T1): phone + OTP, no password, under `/api/provider/v1/auth`
 * (ProviderPhoneAuthController). The person is created on the first code request (`is_new_provider`).
 */
interface AuthApi {
    @POST("${ApiClient.PROVIDER}auth/otp")
    suspend fun requestOtp(@Body body: OtpRequest): Response<ApiEnvelope<OtpSentDto>>

    @POST("${ApiClient.PROVIDER}auth/resend")
    suspend fun resendOtp(@Body body: OtpRequest): Response<ApiEnvelope<OtpSentDto>>

    /** Verifies the code and returns the Bearer token with the person. */
    @POST("${ApiClient.PROVIDER}auth/verify")
    suspend fun verifyOtp(@Body body: VerifyOtpRequest): Response<ApiEnvelope<AuthResultDto>>

    /** Me (ProviderAuthController::me) — asked once when the app opens signed in. */
    @GET("${ApiClient.PROVIDER}me")
    suspend fun me(): Response<ApiEnvelope<ProviderDto>>

    @POST("${ApiClient.PROVIDER}auth/logout")
    suspend fun logout(@Body body: LogoutRequest = LogoutRequest()): Response<ApiEnvelope<Any>>
}

data class OtpRequest(
    @SerializedName("dial_code") val dialCode: String,
    val phone: String,
)

data class OtpSentDto(
    @SerializedName("masked_phone") val maskedPhone: String? = null,
    @SerializedName("is_new_provider") val isNewProvider: Boolean? = null,
    @SerializedName("resend_cooldown_seconds") val resendCooldownSeconds: Int? = null,
)

data class VerifyOtpRequest(
    @SerializedName("dial_code") val dialCode: String,
    val phone: String,
    val code: String,
    /** This install, so the server can list the phone under the person's devices. */
    @SerializedName("device_id") val deviceId: String,
    @SerializedName("device_name") val deviceName: String,
    val platform: String = "android",
)

data class AuthResultDto(
    val provider: ProviderDto? = null,
    val token: String? = null,
)

data class LogoutRequest(
    /** The phone's push id, so the server stops pushing to it. */
    @SerializedName("player_id") val playerId: String? = null,
)

/** The person as the app sees them (`ProviderResource`). */
data class ProviderDto(
    val id: Long? = null,
    val name: String? = null,
    val email: String? = null,
    val phone: String? = null,
    @SerializedName("country_id") val countryId: Int? = null,
    val country: ProviderCountryDto? = null,
    @SerializedName("avatar") val avatarUrl: String? = null,
    val status: String? = null,
    @SerializedName("status_label") val statusLabel: String? = null,
)

data class ProviderCountryDto(
    val id: Int? = null,
    val code: String? = null,
    @SerializedName("dial_code") val dialCode: String? = null,
)
