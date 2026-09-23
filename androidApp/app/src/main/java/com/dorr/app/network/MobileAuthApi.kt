package com.dorr.app.network

import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.POST

interface MobileAuthApi {
    /** POST /api/mobile/v1/auth/otp — combined login/register: sends a phone OTP. */
    @POST("mobile/v1/auth/otp")
    suspend fun requestOtp(@Body body: OtpRequest): ApiEnvelope<OtpSentDto>

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
}