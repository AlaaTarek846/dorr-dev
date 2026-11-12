package com.dorr.freelance.net

import com.dorr.core.network.ApiEnvelope
import com.google.gson.annotations.SerializedName
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.POST
import retrofit2.http.Path

/**
 * My plan (FL-L01), under `/api/freelance/v1`: the plans with their prices in my country, my subscription with its invoices and the free
 * offers I have left, and taking / changing / cancelling / resuming / renewing it. Every action answers with the same body as [show].
 */
interface SubscriptionApi {
    @GET("${ApiClient.FREELANCE}plans")
    suspend fun plans(): Response<ApiEnvelope<PlansDto>>

    @GET("${ApiClient.FREELANCE}subscription")
    suspend fun show(): Response<ApiEnvelope<MySubscriptionDto>>

    @POST("${ApiClient.FREELANCE}subscription")
    suspend fun take(@Body body: TakePlanRequest): Response<ApiEnvelope<MySubscriptionDto>>

    @POST("${ApiClient.FREELANCE}subscription/cancel")
    suspend fun cancel(): Response<ApiEnvelope<MySubscriptionDto>>

    @POST("${ApiClient.FREELANCE}subscription/resume")
    suspend fun resume(): Response<ApiEnvelope<MySubscriptionDto>>

    @POST("${ApiClient.FREELANCE}subscription/renew")
    suspend fun renew(): Response<ApiEnvelope<MySubscriptionDto>>

    /** Extra offers for sale (phase 2): the packs here, what I have left, my last purchases. */
    @GET("${ApiClient.FREELANCE}offer-packs")
    suspend fun offerPacks(): Response<ApiEnvelope<OfferPacksDto>>

    /** [BuyPackRequest.requestId] makes a double tap one purchase. */
    @POST("${ApiClient.FREELANCE}offer-packs/{pack}/buy")
    suspend fun buyPack(@Path("pack") pack: String, @Body body: BuyPackRequest): Response<ApiEnvelope<OfferPacksDto>>
}

/** What the server sells here: [enabled] is false when the service has no plans in my country (the page then says so). */
data class PlansDto(
    val enabled: Boolean = false,
    @field:com.google.gson.annotations.JsonAdapter(CurrencyLabelAdapter::class) val currency: String? = null,
    @SerializedName("currency_symbol") val currencySymbol: String? = null,
    /** Decimal places of [currency]: prices are whole minor units of it. */
    val decimals: Int = 2,
    val plans: List<PlanDto> = emptyList(),
)

data class PlanDto(
    val slug: String = "",
    val name: String = "",
    val description: String? = null,
    val rank: Int = 0,
    val free: Boolean = false,
    /** The plan I am on now (Free when I have none). */
    val current: Boolean = false,
    val prices: PlanPricesDto = PlanPricesDto(),
    @SerializedName("yearly_saving_minor") val yearlySavingMinor: Long? = null,
    val benefits: PlanBenefitsDto = PlanBenefitsDto(),
    /** Days of free trial I would get (0 when I used mine, or the plan has none). */
    @SerializedName("trial_days") val trialDays: Int = 0,
)

/** The two prices of a paid plan; both absent for Free. */
data class PlanPricesDto(val monthly: Long? = null, val yearly: Long? = null)

data class PlanBenefitsDto(
    @SerializedName("offers_unlimited") val offersUnlimited: Boolean = false,
    /** Free offers per month; null when unlimited. */
    @SerializedName("offers_monthly") val offersMonthly: Int? = null,
    /** Hundredths of a percent (800 = 8%); null = the country's commission. */
    @SerializedName("commission_bp") val commissionBp: Int? = null,
    /** Phase 4: the plan's badge by my name, my weight (0-3) in the «Recommended» order, my performance numbers (0 none, 1 basic, 2 full) and the ready-made services I may have at once. */
    val badge: Boolean = false,
    val boost: Int = 0,
    val analytics: Int = 0,
    @SerializedName("services_max") val servicesMax: Int? = null,
)

data class MySubscriptionDto(
    /** The live one, else the last; null when I never had one. */
    val subscription: SubscriptionDto? = null,
    val invoices: List<SubscriptionInvoiceDto> = emptyList(),
    val quota: OfferQuotaDto = OfferQuotaDto(),
)

data class SubscriptionDto(
    val uuid: String = "",
    val plan: PlanRefDto = PlanRefDto(),
    /** `monthly` or `yearly`. */
    val period: String = "monthly",
    /** `trialing`, `active`, `past_due` (a renewal failed; the plan stays through the grace days) or `expired`. */
    val status: String = "",
    /** Whether it gives its benefits now. */
    val active: Boolean = false,
    @SerializedName("auto_renew") val autoRenew: Boolean = true,
    @SerializedName("price_minor") val priceMinor: Long = 0,
    @field:com.google.gson.annotations.JsonAdapter(CurrencyLabelAdapter::class) val currency: String? = null,
    @SerializedName("currency_symbol") val currencySymbol: String? = null,
    @SerializedName("period_end") val periodEnd: String? = null,
    @SerializedName("trial_ends_at") val trialEndsAt: String? = null,
    @SerializedName("grace_until") val graceUntil: String? = null,
    /** A lower plan (or a shorter period) that starts when this one ends. */
    val pending: PendingPlanDto? = null,
)

data class PlanRefDto(val slug: String = "", val name: String = "", val rank: Int = 0)

data class PendingPlanDto(val plan: PlanRefDto = PlanRefDto(), val period: String? = null)

data class SubscriptionInvoiceDto(
    val uuid: String = "",
    val plan: String = "",
    val period: String = "monthly",
    /** `purchase`, `upgrade` or `renewal`. */
    val kind: String = "",
    /** `paid` or `failed`. */
    val status: String = "",
    @SerializedName("amount_minor") val amountMinor: Long = 0,
    @SerializedName("credit_minor") val creditMinor: Long = 0,
    @field:com.google.gson.annotations.JsonAdapter(CurrencyLabelAdapter::class) val currency: String? = null,
    @SerializedName("currency_symbol") val currencySymbol: String? = null,
    @SerializedName("created_at") val createdAt: String? = null,
)

data class TakePlanRequest(val plan: String, val period: String)

/** The extra-offer packs sold in my country (FL-L01, phase 2). [unlimited]: my plan has no limit, so there is nothing to buy. */
data class OfferPacksDto(
    val enabled: Boolean = false,
    @field:com.google.gson.annotations.JsonAdapter(CurrencyLabelAdapter::class) val currency: String? = null,
    @SerializedName("currency_symbol") val currencySymbol: String? = null,
    val decimals: Int = 2,
    val unlimited: Boolean = false,
    /** Days a bought offer lasts (0 = no end). */
    @SerializedName("valid_days") val validDays: Int = 0,
    val credits: OfferCreditsDto = OfferCreditsDto(),
    val packs: List<OfferPackDto> = emptyList(),
    val purchases: List<OfferPurchaseDto> = emptyList(),
    val quota: OfferQuotaDto = OfferQuotaDto(),
)

data class OfferCreditsDto(
    /** Bought offers I can still send. */
    val available: Int = 0,
    @SerializedName("next_expires_at") val nextExpiresAt: String? = null,
    val batches: List<OfferBatchDto> = emptyList(),
)

data class OfferBatchDto(val uuid: String = "", val quantity: Int = 0, val remaining: Int = 0, @SerializedName("expires_at") val expiresAt: String? = null)

data class OfferPackDto(val slug: String = "", val name: String = "", val offers: Int = 0, @SerializedName("price_minor") val priceMinor: Long = 0)

data class OfferPurchaseDto(
    val uuid: String = "",
    val pack: String? = null,
    val quantity: Int = 0,
    @SerializedName("price_minor") val priceMinor: Long = 0,
    @field:com.google.gson.annotations.JsonAdapter(CurrencyLabelAdapter::class) val currency: String? = null,
    @SerializedName("currency_symbol") val currencySymbol: String? = null,
    @SerializedName("created_at") val createdAt: String? = null,
)

data class BuyPackRequest(@SerializedName("request_id") val requestId: String)
