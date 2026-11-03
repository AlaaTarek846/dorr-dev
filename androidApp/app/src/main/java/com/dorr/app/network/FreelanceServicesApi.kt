package com.dorr.app.network

import com.google.gson.annotations.SerializedName
import retrofit2.http.Body
import retrofit2.http.DELETE
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.POST
import retrofit2.http.Path
import retrofit2.http.Query

/**
 * FL-L04, the client's side of ready-made services (`mobile/v1/freelance`): finding services in my country, what a level with extras
 * comes to with the fee, ordering one (a `request_id` makes a double tap one order) and my orders. After the freelancer accepts, the
 * order is a project like any other (its agreement page funds and approves the work).
 */
interface FreelanceServicesApi {
    @GET("mobile/v1/freelance/services")
    suspend fun browse(
        @Header("Authorization") auth: String,
        @Query("search") search: String? = null,
        @Query("specialty_id") specialtyId: Int? = null,
        @Query("max_price") maxPrice: Long? = null,
        @Query("max_days") maxDays: Int? = null,
        @Query("sort") sort: String? = null,
        @Query("freelancer") freelancer: String? = null,
        @Query("page") page: Int = 1,
        @Query("per_page") perPage: Int = 15,
    ): ApiEnvelope<List<MarketServiceCardDto>>

    @GET("mobile/v1/freelance/services/{uuid}")
    suspend fun service(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<MarketServiceDto>

    @GET("mobile/v1/freelance/services/{uuid}/quote")
    suspend fun quote(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Query("level") level: String, @Query("addons[]") addons: List<Int>): ApiEnvelope<MarketQuoteDto>

    @POST("mobile/v1/freelance/services/{uuid}/order")
    suspend fun order(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Body body: MarketOrderRequest): ApiEnvelope<MarketOrderDto>

    /** FL-L10: wait in line while the freelancer is full, or step out of it. Both answer where I stand now. */
    @POST("mobile/v1/freelance/services/{uuid}/waitlist")
    suspend fun joinWaitlist(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<MarketCapacityDto>

    @DELETE("mobile/v1/freelance/services/{uuid}/waitlist")
    suspend fun leaveWaitlist(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<MarketCapacityDto>

    /** `status` = awaiting · active · completed · ended */
    @GET("mobile/v1/freelance/service-orders")
    suspend fun orders(@Header("Authorization") auth: String, @Query("status") status: String? = null, @Query("page") page: Int = 1, @Query("per_page") perPage: Int = 15): ApiEnvelope<List<MarketOrderDto>>

    @GET("mobile/v1/freelance/service-orders/{uuid}")
    suspend fun orderOf(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<MarketOrderDto>

    @POST("mobile/v1/freelance/service-orders/{uuid}/cancel")
    suspend fun cancel(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<MarketOrderDto>

    /** FL-L05: my monthly subscriptions (active first). */
    @GET("mobile/v1/freelance/service-subscriptions")
    suspend fun subscriptions(@Header("Authorization") auth: String, @Query("page") page: Int = 1, @Query("per_page") perPage: Int = 15): ApiEnvelope<List<MarketSubscriptionDto>>

    /** Confirm the next month: made, started and its money held from the wallet in one step. */
    @POST("mobile/v1/freelance/service-subscriptions/{uuid}/renew")
    suspend fun renewSubscription(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<MarketSubscriptionDto>

    /** `action` = cancel (with notice) · keep (take my ending back) */
    @POST("mobile/v1/freelance/service-subscriptions/{uuid}/{action}")
    suspend fun subscriptionAct(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Path("action") action: String): ApiEnvelope<MarketSubscriptionDto>
}

data class MarketFreelancerDto(
    val uuid: String? = null,
    val name: String? = null,
    val headline: String? = null,
    @SerializedName("avatar_url") val avatarUrl: String? = null,
    val rating: Double? = null,
    @SerializedName("reviews_count") val reviewsCount: Int = 0,
    @SerializedName("completed_projects") val completedProjects: Int = 0,
    @SerializedName("on_time_rate") val onTimeRate: Int? = null,
    val badge: FreelancePlanBadgeDto? = null,
    @SerializedName("skill_badges") val skillBadges: List<FreelanceSkillBadgeDto> = emptyList(),
)

data class MarketSpecialtyDto(val id: Int = 0, val name: String? = null)

data class MarketServiceCardDto(
    val uuid: String = "",
    val title: String = "",
    val specialty: MarketSpecialtyDto? = null,
    @field:com.google.gson.annotations.JsonAdapter(CurrencyLabelAdapter::class) val currency: String? = null,
    val decimals: Int = 2,
    @SerializedName("starting_price_minor") val startingPriceMinor: Long? = null,
    @SerializedName("delivery_days") val deliveryDays: Int? = null,
    val levels: Int = 1,
    val cover: String? = null,
    @SerializedName("completed_orders") val completedOrders: Int = 0,
    val freelancer: MarketFreelancerDto = MarketFreelancerDto(),
    /** FL-L10: the freelancer is at their cap of running projects. */
    @SerializedName("at_capacity") val atCapacity: Boolean = false,
    /** FL-L05: once · monthly (the price is then per month). */
    val billing: String = "once",
)

data class MarketServiceDto(
    val uuid: String = "",
    val title: String = "",
    val description: String = "",
    val requirements: String? = null,
    val specialty: MarketSpecialtyDto? = null,
    @field:com.google.gson.annotations.JsonAdapter(CurrencyLabelAdapter::class) val currency: String? = null,
    val decimals: Int = 2,
    @SerializedName("starting_price_minor") val startingPriceMinor: Long? = null,
    val tiers: List<MarketTierDto> = emptyList(),
    val addons: List<MarketAddonDto> = emptyList(),
    val images: List<MarketImageDto> = emptyList(),
    @SerializedName("completed_orders") val completedOrders: Int = 0,
    val freelancer: MarketFreelancerDto = MarketFreelancerDto(),
    @SerializedName("answer_hours") val answerHours: Int = 48,
    val capacity: MarketCapacityDto = MarketCapacityDto(),
    val billing: String = "once",
    /** FL-L05: how a monthly one runs; null for one sold once. */
    val subscription: MarketSubscriptionTermsDto? = null,
    /** FL-L09: the freelancer's loyalty program as it stands for me (null: none, or a monthly service). */
    val loyalty: FlLoyaltyViewDto? = null,
    /** FL-L13: who owns the work once it is paid and what I may do with it (null: the feature is off). */
    val rights: FreelanceRightsSummaryDto? = null,
)

data class MarketSubscriptionTermsDto(
    @SerializedName("period_days") val periodDays: Int = 30,
    @SerializedName("renew_window_days") val renewWindowDays: Int = 3,
    @SerializedName("grace_days") val graceDays: Int = 2,
    @SerializedName("notice_days") val noticeDays: Int = 7,
)

/** FL-L05: my monthly subscription to a service, at the terms kept from its start. */
data class MarketSubscriptionDto(
    val uuid: String = "",
    /** pending · active · ended */
    val status: String = "pending",
    @SerializedName("end_reason") val endReason: String? = null,
    val service: MarketOrderServiceDto = MarketOrderServiceDto(),
    val level: String = "basic",
    @SerializedName("tier_name") val tierName: String? = null,
    @SerializedName("price_minor") val priceMinor: Long = 0,
    @field:com.google.gson.annotations.JsonAdapter(CurrencyLabelAdapter::class) val currency: String? = null,
    val decimals: Int = 2,
    val money: MarketOrderMoneyDto = MarketOrderMoneyDto(),
    val cycle: Int = 1,
    @SerializedName("period_ends_at") val periodEndsAt: String? = null,
    val renew: MarketRenewDto? = null,
    val cancel: MarketSubCancelDto? = null,
    @SerializedName("notice_days") val noticeDays: Int = 7,
    val current: MarketSubMonthDto? = null,
    val freelancer: MarketOrderFreelancerDto? = null,
    val can: MarketSubCanDto = MarketSubCanDto(),
)

data class MarketRenewDto(@SerializedName("opens_at") val opensAt: String? = null, @SerializedName("due_by") val dueBy: String? = null, val open: Boolean = false)

data class MarketSubCancelDto(val by: String = "", @SerializedName("ends_at") val endsAt: String? = null)

data class MarketSubMonthDto(val uuid: String = "", val cycle: Int = 1, val status: String = "")

data class MarketSubCanDto(val renew: Boolean = false, val cancel: Boolean = false, val keep: Boolean = false)

/** FL-L10: whether there is room for me with this freelancer, how many wait, and my own place (null when I am not in line). */
data class MarketCapacityDto(val full: Boolean = false, val waiting: Int = 0, val mine: MarketWaitDto? = null)

/** [status] waiting (at [position]) or offered (the place is kept for me until [holdUntil]). */
data class MarketWaitDto(val status: String = "", val position: Int? = null, @SerializedName("hold_until") val holdUntil: String? = null)

data class MarketTierDto(
    val level: String = "basic",
    val name: String = "",
    val description: String? = null,
    @SerializedName("price_minor") val priceMinor: Long = 0,
    @SerializedName("delivery_days") val deliveryDays: Int = 1,
    val revisions: Int = 0,
    val features: List<String> = emptyList(),
    /** FL-L09: this level's price after my loyalty step (null: none). */
    @SerializedName("loyalty_price_minor") val loyaltyPriceMinor: Long? = null,
)

data class MarketAddonDto(val id: Int = 0, val title: String = "", @SerializedName("price_minor") val priceMinor: Long = 0, @SerializedName("extra_days") val extraDays: Int = 0)

data class MarketImageDto(val id: Int = 0, val url: String? = null)

data class MarketQuoteDto(
    val level: String = "basic",
    @SerializedName("price_minor") val priceMinor: Long = 0,
    @SerializedName("client_fee_minor") val clientFeeMinor: Long = 0,
    @SerializedName("total_minor") val totalMinor: Long = 0,
    @SerializedName("delivery_days") val deliveryDays: Int = 0,
    val revisions: Int = 0,
    @field:com.google.gson.annotations.JsonAdapter(CurrencyLabelAdapter::class) val currency: String? = null,
    val decimals: Int = 2,
    /** FL-L09: the usual price, the discount (basis points) and what it saves me; null: none. */
    @SerializedName("list_price_minor") val listPriceMinor: Long? = null,
    @SerializedName("loyalty_bp") val loyaltyBp: Int? = null,
    @SerializedName("saving_minor") val savingMinor: Long? = null,
)

data class MarketOrderRequest(
    val level: String,
    val addons: List<Int>,
    val note: String?,
    @SerializedName("request_id") val requestId: String,
)

data class MarketOrderDto(
    val uuid: String = "",
    /** awaiting · active · completed · declined · expired · cancelled */
    val status: String = "awaiting",
    val service: MarketOrderServiceDto = MarketOrderServiceDto(),
    val level: String = "basic",
    @SerializedName("tier_name") val tierName: String? = null,
    val features: List<String> = emptyList(),
    val addons: List<MarketOrderAddonDto> = emptyList(),
    @SerializedName("price_minor") val priceMinor: Long = 0,
    /** FL-L09: the usual price and the loyalty discount taken off it; null: none. */
    @SerializedName("list_price_minor") val listPriceMinor: Long? = null,
    @SerializedName("loyalty_bp") val loyaltyBp: Int? = null,
    @SerializedName("saving_minor") val savingMinor: Long? = null,
    @field:com.google.gson.annotations.JsonAdapter(CurrencyLabelAdapter::class) val currency: String? = null,
    val decimals: Int = 2,
    @SerializedName("delivery_days") val deliveryDays: Int = 0,
    val revisions: Int = 0,
    val note: String? = null,
    val project: String? = null,
    /** FL-L05: the monthly subscription this order is one month of. */
    val subscription: MarketOrderSubDto? = null,
    @SerializedName("answer_by") val answerBy: String? = null,
    val money: MarketOrderMoneyDto = MarketOrderMoneyDto(),
    val freelancer: MarketOrderFreelancerDto? = null,
    val can: MarketOrderCanDto = MarketOrderCanDto(),
    @SerializedName("created_at") val createdAt: String? = null,
)

data class MarketOrderServiceDto(val uuid: String? = null, val title: String? = null)

data class MarketOrderAddonDto(val title: String = "", @SerializedName("price_minor") val priceMinor: Long = 0, @SerializedName("extra_days") val extraDays: Int = 0)

data class MarketOrderMoneyDto(
    @SerializedName("price_minor") val priceMinor: Long = 0,
    @SerializedName("client_fee_minor") val clientFeeMinor: Long = 0,
    @SerializedName("total_minor") val totalMinor: Long = 0,
)

data class MarketOrderFreelancerDto(val uuid: String? = null, val name: String? = null)

data class MarketOrderCanDto(val cancel: Boolean = false)

data class MarketOrderSubDto(val uuid: String? = null, val cycle: Int = 1)
