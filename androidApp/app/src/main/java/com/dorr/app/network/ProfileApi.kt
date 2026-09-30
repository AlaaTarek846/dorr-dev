package com.dorr.app.network

import com.google.gson.annotations.SerializedName
import okhttp3.MultipartBody
import retrofit2.http.Body
import retrofit2.http.DELETE
import retrofit2.http.Header
import retrofit2.http.Multipart
import retrofit2.http.POST
import retrofit2.http.PUT
import retrofit2.http.Part

/**
 * The profile endpoints under /api/mobile/v1/profile.
 * Mirrors Modules\User MobileProfileController.
 *
 * Phone and email changes are two-step: `request` validates the new value and
 * sends an OTP to it (nothing changes until confirm), `confirm` swaps the old
 * value for the pending one. Identity (name + gender) and avatar apply at once.
 */
interface ProfileApi {
    @POST("mobile/v1/profile/phone/request")
    suspend fun requestPhoneChange(
        @Header("Authorization") authorization: String,
        @Body body: PhoneChangeRequest,
    ): ApiEnvelope<ChannelOtpDto>

    @POST("mobile/v1/profile/phone/confirm")
    suspend fun confirmPhoneChange(
        @Header("Authorization") authorization: String,
        @Body body: ConfirmCodeBody,
    ): ApiEnvelope<UserDto>

    @PUT("mobile/v1/profile/identity")
    suspend fun updateIdentity(
        @Header("Authorization") authorization: String,
        @Body body: UpdateIdentityBody,
    ): ApiEnvelope<UserDto>

    @Multipart
    @POST("mobile/v1/profile/avatar")
    suspend fun updateAvatar(
        @Header("Authorization") authorization: String,
        @Part avatar: MultipartBody.Part,
    ): ApiEnvelope<UserDto>

    @POST("mobile/v1/profile/email/request")
    suspend fun requestEmailChange(
        @Header("Authorization") authorization: String,
        @Body body: EmailChangeRequest,
    ): ApiEnvelope<ChannelOtpDto>

    @POST("mobile/v1/profile/email/confirm")
    suspend fun confirmEmailChange(
        @Header("Authorization") authorization: String,
        @Body body: ConfirmCodeBody,
    ): ApiEnvelope<UserDto>

    /**
     * DELETE /api/mobile/v1/profile/account
     * Soft-deletes the account and revokes all Sanctum tokens server-side.
     * The account can be recovered by logging in again with the same phone number.
     */
    @DELETE("mobile/v1/profile/account")
    suspend fun deleteAccount(
        @Header("Authorization") authorization: String,
    ): ApiEnvelope<Any?>
}

data class PhoneChangeRequest(
    @SerializedName("dial_code") val dialCode: String,
    val phone: String,
)

data class EmailChangeRequest(
    val email: String,
)

data class ConfirmCodeBody(
    val code: String,
)

data class UpdateIdentityBody(
    val name: String,
    val gender: String,
)

/**
 * What a `request` step returns: the masked destination the code went to
 * (shown in the OTP screen instead of the raw value) and how long the
 * resend button stays disabled. Only one of the masked fields is present.
 */
data class ChannelOtpDto(
    @SerializedName("masked_phone") val maskedPhone: String? = null,
    @SerializedName("masked_email") val maskedEmail: String? = null,
    @SerializedName("resend_cooldown_seconds") val resendCooldownSeconds: Int? = null,
)
