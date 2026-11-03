package com.dorr.app.network

import com.google.gson.annotations.SerializedName
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.Path

/**
 * FL-L09 (part B), the loyalty program on the client's side (`mobile/v1/freelance/...`): every freelancer I finished work with who rewards
 * returning clients — the discount I hold, the next step and how far I am from it — and the same for one freelancer before I buy. The
 * discount itself is applied by the server to a project offer, a ready-made service and a consultation; these only show it.
 */
interface FreelanceLoyaltyApi {
    /** My discounts: the best one first. */
    @GET("mobile/v1/freelance/loyalty")
    suspend fun mine(@Header("Authorization") auth: String): ApiEnvelope<List<FlLoyaltyCardDto>>

    /** One freelancer's program as it stands for me (`loyalty` is null when they run none). */
    @GET("mobile/v1/freelance/freelancers/{uuid}/loyalty")
    suspend fun of(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<FlLoyaltyOfDto>
}

data class FlLoyaltyOfDto(val loyalty: FlLoyaltyViewDto? = null)

/** One step of a ladder: after [after] finished works the client takes [discountBp] (basis points) off the price. */
data class FlLoyaltyTierDto(
    val after: Int = 0,
    @SerializedName("discount_bp") val discountBp: Int = 0,
    val reached: Boolean = false,
    /** Works still to finish for the next step (only on [FlLoyaltyViewDto.next]). */
    val remaining: Int? = null,
)

/** The program as I read it before buying: the ladder, my works with this freelancer, the step I hold, the next and the progress (0–1). */
data class FlLoyaltyViewDto(
    val works: Int = 0,
    val tiers: List<FlLoyaltyTierDto> = emptyList(),
    val current: FlLoyaltyTierDto? = null,
    val next: FlLoyaltyTierDto? = null,
    val progress: Double = 0.0,
)

data class FlLoyaltyFreelancerDto(
    val uuid: String? = null,
    val name: String? = null,
    val headline: String? = null,
    @SerializedName("avatar_url") val avatarUrl: String? = null,
)

/** A freelancer I worked with and the program they run, as it stands for me. */
data class FlLoyaltyCardDto(
    val freelancer: FlLoyaltyFreelancerDto = FlLoyaltyFreelancerDto(),
    val works: Int = 0,
    val tiers: List<FlLoyaltyTierDto> = emptyList(),
    val current: FlLoyaltyTierDto? = null,
    val next: FlLoyaltyTierDto? = null,
    val progress: Double = 0.0,
) {
    fun view() = FlLoyaltyViewDto(works, tiers, current, next, progress)
}
