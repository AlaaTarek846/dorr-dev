package com.dorr.app.network

import com.google.gson.annotations.SerializedName
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.POST
import retrofit2.http.Path
import retrofit2.http.Query

/** DORR points (docs/rides_tasks.md R-146..R-155): the dashboard, history, rewards and spending points. */
interface LoyaltyApi {
    @GET("mobile/v1/rides/loyalty")
    suspend fun dashboard(@Header("Authorization") auth: String): ApiEnvelope<LoyaltyDashboardDto>

    /** `kind`: earned / spent / null = all. */
    @GET("mobile/v1/rides/loyalty/history")
    suspend fun history(@Header("Authorization") auth: String, @Query("kind") kind: String? = null): ApiEnvelope<List<LoyaltyEntryDto>>

    @GET("mobile/v1/rides/loyalty/rewards")
    suspend fun rewards(@Header("Authorization") auth: String): ApiEnvelope<List<LoyaltyRewardDto>>

    @POST("mobile/v1/rides/loyalty/rewards/{id}/redeem")
    suspend fun redeem(@Header("Authorization") auth: String, @Header("Idempotency-Key") key: String, @Path("id") id: Long): ApiEnvelope<LoyaltyRedemptionDto>

    @GET("mobile/v1/rides/loyalty/redemptions")
    suspend fun redemptions(@Header("Authorization") auth: String): ApiEnvelope<List<LoyaltyRedemptionDto>>
}

data class LoyaltyTierDto(
    val id: Long,
    val name: String? = null,
    val color: String? = null,
    @SerializedName("min_points") val minPoints: Long = 0,
    @SerializedName("multiplier_bp") val multiplierBp: Int = 10000,
    val benefits: List<String> = emptyList(),
    val current: Boolean = false,
    @SerializedName("points_to_go") val pointsToGo: Long? = null,
)

data class LoyaltyCommittedDto(val active: Boolean = false, @SerializedName("bonus_bp") val bonusBp: Int = 0, @SerializedName("window_days") val windowDays: Int = 30)

data class LoyaltyBadgeDto(
    val id: Long,
    val name: String? = null,
    val description: String? = null,
    val icon: String = "star",
    val threshold: Long = 1,
    val progress: Long = 0,
    @SerializedName("reward_points") val rewardPoints: Long = 0,
    @SerializedName("earned_at") val earnedAt: String? = null,
)

data class LoyaltyChallengeDto(
    val id: Long,
    val title: String? = null,
    val description: String? = null,
    val rule: String = "rides",
    val target: Long = 1,
    val progress: Long = 0,
    @SerializedName("reward_points") val rewardPoints: Long = 0,
    @SerializedName("ends_at") val endsAt: String? = null,
    val live: Boolean = true,
    val failed: Boolean = false,
    @SerializedName("completed_at") val completedAt: String? = null,
)

data class LoyaltyEntryDto(
    val id: Long,
    val points: Long,
    @SerializedName("balance_after") val balanceAfter: Long = 0,
    val kind: String = "ride",
    val title: String? = null,
    @SerializedName("created_at") val createdAt: String? = null,
)

data class LoyaltyDashboardDto(
    val enabled: Boolean = false,
    val balance: Long = 0,
    val lifetime: Long = 0,
    @SerializedName("points_per_unit") val pointsPerUnit: Int = 1,
    @SerializedName("window_days") val windowDays: Int = 365,
    @SerializedName("window_points") val windowPoints: Long = 0,
    val tier: LoyaltyTierDto? = null,
    @SerializedName("next_tier") val nextTier: LoyaltyTierDto? = null,
    val progress: Double = 0.0,
    val tiers: List<LoyaltyTierDto> = emptyList(),
    val committed: LoyaltyCommittedDto = LoyaltyCommittedDto(),
    val badges: List<LoyaltyBadgeDto> = emptyList(),
    val challenges: List<LoyaltyChallengeDto> = emptyList(),
    val recent: List<LoyaltyEntryDto> = emptyList(),
)

data class LoyaltyRewardDto(
    val id: Long,
    val title: String? = null,
    val description: String? = null,
    @SerializedName("cost_points") val costPoints: Long = 0,
    val kind: String = "wallet_credit",
    @SerializedName("value_minor") val valueMinor: Long = 0,
    @SerializedName("coupon_percent") val couponPercent: Int? = null,
    @SerializedName("coupon_days") val couponDays: Int? = null,
    val stock: Long? = null,
    @SerializedName("min_tier") val minTier: String? = null,
    val currency: RideCurrencyDto? = null,
    val available: Boolean = false,
    /** out_of_stock / limit / tier / points */
    val reason: String? = null,
)

data class LoyaltyRedemptionResultDto(
    @SerializedName("coupon_code") val couponCode: String? = null,
    @SerializedName("expires_at") val expiresAt: String? = null,
    @SerializedName("credit_minor") val creditMinor: Long? = null,
)

data class LoyaltyRedemptionDto(
    val uuid: String,
    val points: Long = 0,
    val title: String? = null,
    val kind: String? = null,
    val result: LoyaltyRedemptionResultDto? = null,
    @SerializedName("created_at") val createdAt: String? = null,
)
