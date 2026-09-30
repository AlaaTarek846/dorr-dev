package com.dorr.app.network

import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.POST

interface MobileAuthApi {
    /** POST /api/mobile/v1/auth/otp — combined login/register: sends a phone OTP. */
    @POST("mobile/v1/auth/otp")
    suspend fun requestOtp(@Body body: OtpRequest): ApiEnvelope<OtpSentDto>

    /** POST /api/mobile/v1/auth/otp/restore — sends the OTP for a soft-deleted account. */
    @POST("mobile/v1/auth/otp/restore")
    suspend fun requestRestoreOtp(@Body body: OtpRequest): ApiEnvelope<OtpSentDto>

    /** POST /api/mobile/v1/auth/resend — resend the phone OTP. */
    @POST("mobile/v1/auth/resend")
    suspend fun resendOtp(@Body body: OtpRequest): ApiEnvelope<OtpSentDto>

    /** POST /api/mobile/v1/auth/verify — verify the OTP and get a Bearer token. */
    @POST("mobile/v1/auth/verify")
    suspend fun verifyOtp(@Body body: VerifyOtpRequest): ApiEnvelope<AuthResultDto>

    /** GET /api/mobile/v1/auth/me — authenticated profile. */
    @GET("mobile/v1/auth/me")
    suspend fun me(@Header("Authorization") authorization: String): ApiEnvelope<UserDto>

    /** POST /api/mobile/v1/auth/logout — revoke the current bearer token. */
    @POST("mobile/v1/auth/logout")
    suspend fun logout(@Header("Authorization") authorization: String): ApiEnvelope<Any?>

    /**
     * A code goes to the *new* number. Behind the wallet PIN once one exists — a first attempt
     * without [pin] on such an account fails with `wallet_pin_required`, so the caller can ask for
     * the PIN and retry with it, exactly like every other PIN-gated action.
     */
    @POST("mobile/v1/phone/change")
    suspend fun startPhoneChange(
        @Header("Authorization") authorization: String,
        @Body body: OtpRequest,
        @Header("X-Wallet-Pin") pin: String? = null,
    ): ApiEnvelope<Any?>

    @POST("mobile/v1/phone/change/confirm")
    suspend fun confirmPhoneChange(
        @Header("Authorization") authorization: String,
        @Body body: PhoneChangeCodeRequest,
    ): ApiEnvelope<PhoneChangedDto>
}

data class PhoneChangeCodeRequest(val code: String)

data class PhoneChangedDto(val phone: String)