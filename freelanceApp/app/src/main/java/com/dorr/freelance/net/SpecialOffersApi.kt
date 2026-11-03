package com.dorr.freelance.net

import com.dorr.core.network.ApiEnvelope
import com.google.gson.annotations.SerializedName
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.POST
import retrofit2.http.Path
import retrofit2.http.Query

/**
 * FL-L09, special offers to clients I finished work with, under `/api/freelance/v1/special-offers`: who I can offer, what I can offer
 * (custom work, one of my services at a lower price, one of my consultations at a discount), what a price leaves me, sending, my offers
 * and taking one back before it is answered. The client's acceptance starts the work at once.
 */
interface SpecialOffersApi {
    /** `tab` = open · accepted · past */
    @GET("${ApiClient.FREELANCE}special-offers")
    suspend fun list(@Query("tab") tab: String? = null, @Query("page") page: Int = 1, @Query("per_page") perPage: Int = 15): Response<ApiEnvelope<List<SpecialOfferDto>>>

    @GET("${ApiClient.FREELANCE}special-offers/clients")
    suspend fun clients(@Query("search") search: String? = null): Response<ApiEnvelope<List<PastClientDto>>>

    /** My services and consultations that can be offered, my specialties, the rules, today's room and how my offers did. */
    @GET("${ApiClient.FREELANCE}special-offers/compose")
    suspend fun compose(): Response<ApiEnvelope<SpecialOfferComposeDto>>

    @GET("${ApiClient.FREELANCE}special-offers/quote")
    suspend fun quote(@Query("price_minor") priceMinor: Long, @Query("specialty_id") specialtyId: Int? = null): Response<ApiEnvelope<SpecialOfferQuoteDto>>

    @POST("${ApiClient.FREELANCE}special-offers")
    suspend fun send(@Body body: SpecialOfferRequest): Response<ApiEnvelope<SpecialOfferDto>>

    @GET("${ApiClient.FREELANCE}special-offers/{uuid}")
    suspend fun show(@Path("uuid") uuid: String): Response<ApiEnvelope<SpecialOfferDto>>

    @POST("${ApiClient.FREELANCE}special-offers/{uuid}/withdraw")
    suspend fun withdraw(@Path("uuid") uuid: String): Response<ApiEnvelope<SpecialOfferDto>>
}

data class PastClientDto(
    val key: String = "",
    val name: String? = null,
    @SerializedName("avatar_url") val avatarUrl: String? = null,
    val works: Int = 0,
    val projects: Int = 0,
    val consultations: Int = 0,
    @SerializedName("last_title") val lastTitle: String? = null,
    @SerializedName("last_at") val lastAt: String? = null,
    @SerializedName("open_offer") val openOffer: String? = null,
    @SerializedName("can_send") val canSend: Boolean = true,
    /** has_open · cooldown */
    @SerializedName("blocked_reason") val blockedReason: String? = null,
    @SerializedName("blocked_until") val blockedUntil: String? = null,
)

data class SpecialOfferComposeDto(
    val currency: String? = null,
    val decimals: Int = 2,
    /** Which kinds can be sent now (custom · service · consultation). */
    val kinds: Map<String, Boolean> = emptyMap(),
    val services: List<SpecialServiceDto> = emptyList(),
    val consultations: List<SpecialConsultationDto> = emptyList(),
    val specialties: List<SpecialSpecialtyDto> = emptyList(),
    val rules: SpecialOfferRulesDto = SpecialOfferRulesDto(),
    @SerializedName("min_price_minor") val minPriceMinor: Long? = null,
    @SerializedName("today_left") val todayLeft: Int = 0,
    val stats: SpecialOfferStatsDto = SpecialOfferStatsDto(),
    val counts: Map<String, Int> = emptyMap(),
)

data class SpecialServiceDto(val uuid: String = "", val title: String? = null, val levels: List<SpecialLevelDto> = emptyList())

data class SpecialLevelDto(
    val level: String = "basic",
    val label: String? = null,
    val name: String? = null,
    @SerializedName("price_minor") val priceMinor: Long = 0,
    @SerializedName("delivery_days") val deliveryDays: Int = 0,
    val revisions: Int = 0,
    val features: List<String> = emptyList(),
)

data class SpecialConsultationDto(val uuid: String = "", val title: String? = null, val lengths: List<SpecialLengthDto> = emptyList())

data class SpecialLengthDto(
    val minutes: Int = 0,
    @SerializedName("price_minor") val priceMinor: Long = 0,
    @SerializedName("list_price_minor") val listPriceMinor: Long? = null,
    @SerializedName("client_fee_minor") val clientFeeMinor: Long = 0,
    @SerializedName("total_minor") val totalMinor: Long = 0,
)

data class SpecialSpecialtyDto(val id: Int = 0, val name: String? = null, @SerializedName("is_primary") val isPrimary: Boolean = false)

data class SpecialOfferRulesDto(
    @SerializedName("daily_limit") val dailyLimit: Int = 10,
    @SerializedName("open_per_client") val openPerClient: Int = 1,
    @SerializedName("cooldown_days") val cooldownDays: Int = 7,
    @SerializedName("max_valid_days") val maxValidDays: Int = 14,
    @SerializedName("default_valid_days") val defaultValidDays: Int = 7,
    @SerializedName("min_discount_bp") val minDiscountBp: Int = 500,
    @SerializedName("max_discount_bp") val maxDiscountBp: Int = 9000,
)

data class SpecialOfferStatsDto(val sent: Int = 0, val seen: Int = 0, val accepted: Int = 0, @SerializedName("accept_rate") val acceptRate: Int = 0)

data class SpecialOfferQuoteDto(
    @SerializedName("price_minor") val priceMinor: Long = 0,
    @SerializedName("commission_minor") val commissionMinor: Long = 0,
    @SerializedName("net_minor") val netMinor: Long = 0,
    @SerializedName("client_fee_minor") val clientFeeMinor: Long = 0,
    @SerializedName("client_total_minor") val clientTotalMinor: Long = 0,
)

data class SpecialOfferRequest(
    val client: String,
    val kind: String,
    val title: String? = null,
    val description: String? = null,
    val message: String? = null,
    @SerializedName("specialty_id") val specialtyId: Int? = null,
    val service: String? = null,
    val level: String? = null,
    val consultation: String? = null,
    @SerializedName("price_minor") val priceMinor: Long? = null,
    @SerializedName("discount_bp") val discountBp: Int? = null,
    @SerializedName("delivery_days") val deliveryDays: Int? = null,
    val revisions: Int? = null,
    @SerializedName("valid_days") val validDays: Int? = null,
    @SerializedName("request_id") val requestId: String,
)

data class SpecialOfferDto(
    val uuid: String = "",
    /** custom · service · consultation */
    val kind: String = "custom",
    @SerializedName("kind_label") val kindLabel: String? = null,
    /** sent · accepted · declined · withdrawn · expired */
    val status: String = "sent",
    @SerializedName("status_label") val statusLabel: String? = null,
    val title: String? = null,
    val message: String? = null,
    val description: String? = null,
    val specialty: SpecialSpecialtyDto? = null,
    val service: SpecialOfferServiceDto? = null,
    val consultation: SpecialOfferConsultationDto? = null,
    @SerializedName("price_minor") val priceMinor: Long? = null,
    @SerializedName("list_price_minor") val listPriceMinor: Long? = null,
    @SerializedName("saving_minor") val savingMinor: Long? = null,
    @SerializedName("discount_bp") val discountBp: Int? = null,
    @SerializedName("delivery_days") val deliveryDays: Int? = null,
    val revisions: Int? = null,
    val currency: String? = null,
    val decimals: Int = 2,
    val money: SpecialOfferMoneyDto? = null,
    @SerializedName("expires_at") val expiresAt: String? = null,
    @SerializedName("sent_at") val sentAt: String? = null,
    @SerializedName("viewed_at") val viewedAt: String? = null,
    @SerializedName("answered_at") val answeredAt: String? = null,
    @SerializedName("decline_reason") val declineReason: String? = null,
    val client: SpecialOfferClientDto? = null,
    val result: SpecialOfferResultDto? = null,
    val can: SpecialOfferCanDto = SpecialOfferCanDto(),
)

data class SpecialOfferServiceDto(
    val uuid: String? = null,
    val title: String? = null,
    val level: String? = null,
    @SerializedName("level_label") val levelLabel: String? = null,
    @SerializedName("tier_name") val tierName: String? = null,
    val features: List<String> = emptyList(),
)

data class SpecialOfferConsultationDto(val uuid: String? = null, val title: String? = null, val lengths: List<SpecialLengthDto> = emptyList())

data class SpecialOfferMoneyDto(
    @SerializedName("price_minor") val priceMinor: Long = 0,
    @SerializedName("commission_minor") val commissionMinor: Long = 0,
    @SerializedName("net_minor") val netMinor: Long = 0,
)

data class SpecialOfferClientDto(val key: String? = null, val name: String? = null, @SerializedName("avatar_url") val avatarUrl: String? = null)

data class SpecialOfferResultDto(
    val project: String? = null,
    @SerializedName("service_order") val serviceOrder: String? = null,
    val consultation: String? = null,
)

data class SpecialOfferCanDto(val withdraw: Boolean = false)
