package com.dorr.freelance.net

import com.dorr.core.network.ApiEnvelope
import com.google.gson.annotations.SerializedName
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.POST

/** The freelancer's sign-in (FL-003): phone + OTP, no password. under `/api/freelance/v1/auth`. */
interface AuthApi {
    /** Sends the code; the freelancer is created on the first request (`is_new_freelancer`). */
    @POST("${ApiClient.FREELANCE}auth/otp")
    suspend fun requestOtp(@Body body: OtpRequest): Response<ApiEnvelope<OtpSentDto>>

    @POST("${ApiClient.FREELANCE}auth/resend")
    suspend fun resendOtp(@Body body: OtpRequest): Response<ApiEnvelope<OtpSentDto>>

    /** Verifies the code and returns the Bearer token with the freelancer. */
    @POST("${ApiClient.FREELANCE}auth/verify")
    suspend fun verifyOtp(@Body body: VerifyOtpRequest): Response<ApiEnvelope<AuthResultDto>>

    /** Me — asked once when the app opens signed in. */
    @GET("${ApiClient.FREELANCE}auth/me")
    suspend fun me(): Response<ApiEnvelope<FreelancerDto>>

    @POST("${ApiClient.FREELANCE}auth/logout")
    suspend fun logout(@Body body: LogoutRequest = LogoutRequest()): Response<ApiEnvelope<Any>>
}

data class OtpRequest(
    @SerializedName("dial_code") val dialCode: String,
    val phone: String,
)

data class OtpSentDto(
    @SerializedName("masked_phone") val maskedPhone: String? = null,
    @SerializedName("is_new_freelancer") val isNewFreelancer: Boolean? = null,
    @SerializedName("resend_cooldown_seconds") val resendCooldownSeconds: Int? = null,
)

data class VerifyOtpRequest(
    @SerializedName("dial_code") val dialCode: String,
    val phone: String,
    val code: String,
    /** This install, so the server lists the phone under the freelancer's devices. */
    @SerializedName("device_id") val deviceId: String,
    @SerializedName("device_name") val deviceName: String,
    val platform: String = "android",
)

data class AuthResultDto(
    val freelancer: FreelancerDto? = null,
    val token: String? = null,
)

data class LogoutRequest(
    /** The phone's push id, once push is wired (step 7). */
    @SerializedName("player_id") val playerId: String? = null,
)

/** The freelancer as their own app sees them (`FreelancerResource`). */
data class FreelancerDto(
    val uuid: String? = null,
    val name: String? = null,
    val phone: String? = null,
    val headline: String? = null,
    val bio: String? = null,
    @SerializedName("years_of_experience") val yearsOfExperience: Int? = null,
    @SerializedName("contact_languages") val contactLanguages: List<String>? = null,
    val city: String? = null,
    val email: String? = null,
    @SerializedName("email_verified") val emailVerified: Boolean = false,
    val gender: String? = null,
    @SerializedName("date_of_birth") val dateOfBirth: String? = null,
    @SerializedName("country_id") val countryId: Int? = null,
    val locale: String? = null,
    val residency: String? = null,
    val status: String? = null,
    @SerializedName("status_reason") val statusReason: String? = null,
    @SerializedName("avatar_url") val avatarUrl: String? = null,
    val completeness: CompletenessDto? = null,
    val availability: MyAvailabilityDto? = null,
    val terms: TermsDto? = null,
)

/** Whether new work can be given to the freelancer now (a pause or time off says no) — FL-106. */
data class MyAvailabilityDto(
    @SerializedName("can_receive_offers") val canReceiveOffers: Boolean = true,
    @SerializedName("accepting_requests") val acceptingRequests: Boolean = true,
    @SerializedName("on_time_off") val onTimeOff: Boolean = false,
)

data class CompletenessDto(
    val percent: Int = 0,
    val missing: List<String> = emptyList(),
)

data class TermsDto(
    val accepted: Boolean = false,
    @SerializedName("current_version") val currentVersion: String? = null,
)
