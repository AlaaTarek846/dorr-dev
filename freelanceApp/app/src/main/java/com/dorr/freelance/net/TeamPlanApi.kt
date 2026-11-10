package com.dorr.freelance.net

import com.dorr.core.network.ApiEnvelope
import com.google.gson.annotations.SerializedName
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.POST
import retrofit2.http.Path

/**
 * A team's plan (FS-211), under `/api/freelance/v1/teams/{team}/subscription`: the plans on sale for the team with what each opens, the team's
 * subscription with its invoices, and what the team holds against its room. Owners only: an Owner pays out of their own wallet. Every action
 * answers with the same body as [show].
 */
interface TeamPlanApi {
    @GET("${ApiClient.FREELANCE}teams/{team}/subscription")
    suspend fun show(@Path("team") team: String): Response<ApiEnvelope<TeamPlanPageDto>>

    /** Takes a plan; when one is live this changes it (a better plan starts now, a lower one at the end of the period). */
    @POST("${ApiClient.FREELANCE}teams/{team}/subscription")
    suspend fun take(@Path("team") team: String, @Body body: TakePlanRequest): Response<ApiEnvelope<TeamPlanPageDto>>

    /** Renewal off - any Owner. */
    @POST("${ApiClient.FREELANCE}teams/{team}/subscription/cancel")
    suspend fun cancel(@Path("team") team: String): Response<ApiEnvelope<TeamPlanPageDto>>

    /** Renewal back on - the Owner who pays. */
    @POST("${ApiClient.FREELANCE}teams/{team}/subscription/resume")
    suspend fun resume(@Path("team") team: String): Response<ApiEnvelope<TeamPlanPageDto>>

    /** A failed renewal tried again now - the Owner who pays. */
    @POST("${ApiClient.FREELANCE}teams/{team}/subscription/renew")
    suspend fun renew(@Path("team") team: String): Response<ApiEnvelope<TeamPlanPageDto>>
}

data class TeamPlanPageDto(
    val catalog: TeamPlanCatalogDto = TeamPlanCatalogDto(),
    /** The live one, else the last; null when the team never had one. */
    val subscription: TeamSubscriptionDto? = null,
    val invoices: List<SubscriptionInvoiceDto> = emptyList(),
    val usage: TeamUsageDto = TeamUsageDto(),
)

/** The first plan is always the free baseline (what the team has with no plan); the others are only the plans that open more room here. */
data class TeamPlanCatalogDto(
    @field:com.google.gson.annotations.JsonAdapter(CurrencyLabelAdapter::class) val currency: String? = null,
    @SerializedName("currency_symbol") val currencySymbol: String? = null,
    /** Decimal places of [currency]: prices are whole minor units of it. */
    val decimals: Int = 2,
    val plans: List<TeamPlanDto> = emptyList(),
)

data class TeamPlanDto(
    val slug: String = "",
    val name: String = "",
    val description: String? = null,
    val rank: Int = 0,
    val free: Boolean = false,
    /** The plan the team is on now (the free baseline when it has none). */
    val current: Boolean = false,
    val prices: PlanPricesDto = PlanPricesDto(),
    @SerializedName("yearly_saving_minor") val yearlySavingMinor: Long? = null,
    val benefits: TeamPlanBenefitsDto = TeamPlanBenefitsDto(),
    /** Days of free trial the team would get (0 when it used its trial, or the plan has none). */
    @SerializedName("trial_days") val trialDays: Int = 0,
)

/** What a plan opens for the whole team; `null` = no cap. */
data class TeamPlanBenefitsDto(
    @SerializedName("members_max") val membersMax: Int? = null,
    @SerializedName("projects_max") val projectsMax: Int? = null,
    @SerializedName("offers_monthly") val offersMonthly: Int? = null,
)

data class TeamSubscriptionDto(
    val uuid: String = "",
    val plan: PlanRefDto = PlanRefDto(),
    /** `monthly` or `yearly`. */
    val period: String = "monthly",
    /** `trialing`, `active`, `past_due` (a renewal failed; the plan stays through the grace days) or `expired`. */
    val status: String = "",
    /** Whether it gives its room now. */
    val active: Boolean = false,
    @SerializedName("auto_renew") val autoRenew: Boolean = true,
    @SerializedName("price_minor") val priceMinor: Long = 0,
    @field:com.google.gson.annotations.JsonAdapter(CurrencyLabelAdapter::class) val currency: String? = null,
    @SerializedName("period_end") val periodEnd: String? = null,
    @SerializedName("trial_ends_at") val trialEndsAt: String? = null,
    @SerializedName("grace_until") val graceUntil: String? = null,
    /** A lower plan (or a shorter period) that starts when this one ends. */
    val pending: PendingPlanDto? = null,
    /** The Owner whose wallet pays; [paidByMe] says whether the buttons that spend (change, resume, pay now) are mine. */
    @SerializedName("paid_by") val paidBy: TeamPayerDto? = null,
    @SerializedName("paid_by_me") val paidByMe: Boolean = false,
)

data class TeamPayerDto(val name: String = "")

/** What the team holds against its room right now. */
data class TeamUsageDto(
    val plan: TeamUsagePlanDto = TeamUsagePlanDto(),
    val members: TeamMeterDto = TeamMeterDto(),
    val projects: TeamMeterDto = TeamMeterDto(),
    val offers: TeamOffersMeterDto = TeamOffersMeterDto(),
)

data class TeamUsagePlanDto(val slug: String? = null, val name: String? = null, val free: Boolean = true)

/** [used] of [max]; a null [max] is no cap. */
data class TeamMeterDto(val used: Int = 0, val max: Int? = null)

data class TeamOffersMeterDto(val used: Int = 0, val limit: Int? = null, val left: Int? = null)
