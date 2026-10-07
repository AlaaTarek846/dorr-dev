package com.dorr.app.network

import com.google.gson.annotations.SerializedName
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.POST

interface ReferralApi {
    @GET("mobile/v1/referrals/my-code")
    suspend fun myCode(
        @Header("Authorization") authorization: String,
    ): ApiEnvelope<MyReferralCodeDto>

    @POST("mobile/v1/referrals/track")
    suspend fun track(
        @Header("Authorization") authorization: String,
        @Body body: TrackReferralRequest,
    ): ApiEnvelope<TrackedReferralDto>
}

data class TrackReferralRequest(val referral_code: String)

data class MyReferralCodeDto(
    val code: String? = null,
    @SerializedName("is_active") val isActive: Boolean = true,
    val applied: AppliedReferralDto? = null,
)

data class AppliedReferralDto(
    val code: String? = null,
    val status: String? = null,
)

data class TrackedReferralDto(
    val id: Int = 0,
    val status: String? = null,
    val referral_code: String? = null,
)
