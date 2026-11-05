package com.dorr.freelance.net

import com.dorr.core.network.ApiEnvelope
import com.google.gson.annotations.SerializedName
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.POST
import retrofit2.http.PUT
import retrofit2.http.Path

/**
 * FL-L09 (part B), my loyalty program under `/api/freelance/v1/loyalty`: the steps of discount a client earns by coming back (after this many
 * works finished together → this much off), within the platform's limits; on / off; my clients on a step and what the discounts came to.
 * The discount is taken by itself on a project offer, a service order and a consultation, and it is mine to give: it comes off my price.
 */
interface LoyaltyApi {
    @GET("${ApiClient.FREELANCE}loyalty")
    suspend fun show(): Response<ApiEnvelope<LoyaltyDashDto>>

    /** Always the whole ladder. */
    @PUT("${ApiClient.FREELANCE}loyalty")
    suspend fun save(@Body body: LoyaltyRequest): Response<ApiEnvelope<LoyaltyDashDto>>

    /** `enable` · `disable` — without touching the steps. */
    @POST("${ApiClient.FREELANCE}loyalty/{action}")
    suspend fun toggle(@Path("action") action: String): Response<ApiEnvelope<LoyaltyDashDto>>
}

data class LoyaltyDashDto(
    val available: Boolean = true,
    @field:com.google.gson.annotations.JsonAdapter(CurrencyLabelAdapter::class) val currency: String? = null,
    val decimals: Int = 2,
    /** Null until I set one up. */
    val program: LoyaltyProgramDto? = null,
    val rules: LoyaltyRulesDto = LoyaltyRulesDto(),
    val presets: List<LoyaltyPresetDto> = emptyList(),
    val stats: LoyaltyStatsDto = LoyaltyStatsDto(),
    val clients: List<LoyaltyClientDto> = emptyList(),
)

data class LoyaltyProgramDto(val enabled: Boolean = true, val tiers: List<LoyaltyTierDto> = emptyList())

/** One step: after [after] works finished together the client gets [discountBp] off (basis points: 1000 = 10%). [clients] is how many stand on it. */
data class LoyaltyTierDto(
    val after: Int = 1,
    @SerializedName("discount_bp") val discountBp: Int = 0,
    val clients: Int = 0,
)

data class LoyaltyRulesDto(
    @SerializedName("min_discount_bp") val minDiscountBp: Int = 100,
    @SerializedName("max_discount_bp") val maxDiscountBp: Int = 3000,
    @SerializedName("max_tiers") val maxTiers: Int = 4,
    @SerializedName("max_after") val maxAfter: Int = 50,
)

data class LoyaltyPresetDto(val key: String = "", val name: String = "", val tiers: List<LoyaltyTierDto> = emptyList())

data class LoyaltyStatsDto(
    @SerializedName("past_clients") val pastClients: Int = 0,
    @SerializedName("in_program") val inProgram: Int = 0,
    val uses: Int = 0,
    @SerializedName("saved_minor") val savedMinor: Long = 0,
    @SerializedName("by_tier") val byTier: List<LoyaltyTierDto> = emptyList(),
)

data class LoyaltyClientDto(
    val name: String = "",
    @SerializedName("avatar_url") val avatarUrl: String? = null,
    val works: Int = 0,
    val current: LoyaltyTierDto? = null,
    val next: LoyaltyNextDto? = null,
)

data class LoyaltyNextDto(val after: Int = 0, @SerializedName("discount_bp") val discountBp: Int = 0, val remaining: Int = 0)

data class LoyaltyRequest(val enabled: Boolean, val tiers: List<LoyaltyStepRequest>)

data class LoyaltyStepRequest(val after: Int, @SerializedName("discount_bp") val discountBp: Int)
