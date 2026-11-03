package com.dorr.freelance.net

import com.dorr.core.network.ApiEnvelope
import com.google.gson.annotations.SerializedName
import okhttp3.MultipartBody
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.DELETE
import retrofit2.http.GET
import retrofit2.http.Multipart
import retrofit2.http.POST
import retrofit2.http.PUT
import retrofit2.http.Part
import retrofit2.http.Path
import retrofit2.http.Query

/**
 * FL-L04, ready-made services under `/api/freelance/v1`: my services (levels, extras, images; sending for review, pausing, deleting)
 * and the orders clients placed on them (accepting one starts its agreement; declining ends it).
 */
interface ServicesApi {
    @GET("${ApiClient.FREELANCE}services")
    suspend fun mine(@Query("status") status: String? = null): Response<ApiEnvelope<MyServicesDto>>

    @GET("${ApiClient.FREELANCE}services/{uuid}")
    suspend fun show(@Path("uuid") uuid: String): Response<ApiEnvelope<ServiceDto>>

    @POST("${ApiClient.FREELANCE}services")
    suspend fun create(@Body body: ServiceRequest): Response<ApiEnvelope<ServiceDto>>

    @PUT("${ApiClient.FREELANCE}services/{uuid}")
    suspend fun update(@Path("uuid") uuid: String, @Body body: ServiceRequest): Response<ApiEnvelope<ServiceDto>>

    @DELETE("${ApiClient.FREELANCE}services/{uuid}")
    suspend fun delete(@Path("uuid") uuid: String): Response<ApiEnvelope<Any>>

    /** `action` = submit · pause · resume */
    @POST("${ApiClient.FREELANCE}services/{uuid}/{action}")
    suspend fun act(@Path("uuid") uuid: String, @Path("action") action: String): Response<ApiEnvelope<ServiceDto>>

    @Multipart
    @POST("${ApiClient.FREELANCE}services/{uuid}/images")
    suspend fun addImage(@Path("uuid") uuid: String, @Part image: MultipartBody.Part): Response<ApiEnvelope<ServiceDto>>

    @DELETE("${ApiClient.FREELANCE}services/{uuid}/images/{media}")
    suspend fun deleteImage(@Path("uuid") uuid: String, @Path("media") media: Int): Response<ApiEnvelope<ServiceDto>>

    /** `status` = awaiting · active · completed · ended */
    @GET("${ApiClient.FREELANCE}service-orders")
    suspend fun orders(@Query("status") status: String? = null, @Query("page") page: Int = 1, @Query("per_page") perPage: Int = 15): Response<ApiEnvelope<List<ServiceOrderDto>>>

    @GET("${ApiClient.FREELANCE}service-orders/{uuid}")
    suspend fun order(@Path("uuid") uuid: String): Response<ApiEnvelope<ServiceOrderDto>>

    /** `action` = accept · decline */
    @POST("${ApiClient.FREELANCE}service-orders/{uuid}/{action}")
    suspend fun answer(@Path("uuid") uuid: String, @Path("action") action: String, @Body body: OrderAnswerRequest = OrderAnswerRequest()): Response<ApiEnvelope<ServiceOrderDto>>

    /** FL-L05: the monthly subscriptions to my services. `status` = pending · active · ended */
    @GET("${ApiClient.FREELANCE}service-subscriptions")
    suspend fun subscriptions(@Query("status") status: String? = null, @Query("page") page: Int = 1, @Query("per_page") perPage: Int = 15): Response<ApiEnvelope<List<ServiceSubscriptionDto>>>

    /** `action` = cancel (with notice) · keep (take my ending back) */
    @POST("${ApiClient.FREELANCE}service-subscriptions/{uuid}/{action}")
    suspend fun subscriptionAct(@Path("uuid") uuid: String, @Path("action") action: String): Response<ApiEnvelope<ServiceSubscriptionDto>>
}

data class MyServicesDto(
    val services: List<ServiceDto> = emptyList(),
    val counts: Map<String, Int> = emptyMap(),
    val limits: ServiceLimitsDto = ServiceLimitsDto(),
    @field:com.google.gson.annotations.JsonAdapter(CurrencyLabelAdapter::class) val currency: String? = null,
    val decimals: Int = 2,
    val price: PriceRuleDto = PriceRuleDto(),
    /** The staff look at a service before clients see it. */
    val review: Boolean = true,
    @SerializedName("answer_hours") val answerHours: Int = 48,
)

data class ServiceLimitsDto(
    @SerializedName("max_images") val maxImages: Int = 5,
    @SerializedName("max_addons") val maxAddons: Int = 5,
    @SerializedName("max_features") val maxFeatures: Int = 10,
    @SerializedName("max_active") val maxActive: Int = 5,
)

data class PriceRuleDto(@SerializedName("min_minor") val minMinor: Long? = null, @SerializedName("max_minor") val maxMinor: Long? = null)

data class ServiceDto(
    val uuid: String = "",
    val title: String = "",
    val description: String = "",
    val requirements: String? = null,
    /** FL-L05: once · monthly (the prices are then per month). */
    val billing: String = "once",
    val specialty: ServiceSpecialtyDto? = null,
    @field:com.google.gson.annotations.JsonAdapter(CurrencyLabelAdapter::class) val currency: String? = null,
    val decimals: Int = 2,
    @SerializedName("starting_price_minor") val startingPriceMinor: Long? = null,
    val tiers: List<ServiceTierDto> = emptyList(),
    val addons: List<ServiceAddonDto> = emptyList(),
    val images: List<ServiceImageDto> = emptyList(),
    /** draft · pending_review · published · paused · rejected · suspended */
    val status: String = "draft",
    @SerializedName("status_label") val statusLabel: String? = null,
    @SerializedName("status_reason") val statusReason: String? = null,
    val orders: ServiceOrderCountsDto = ServiceOrderCountsDto(),
    val can: ServiceCanDto = ServiceCanDto(),
    /** FL-L13: the terms every order of it starts from (null where the feature is off). */
    val rights: RightsSummaryDto? = null,
    @SerializedName("published_at") val publishedAt: String? = null,
    @SerializedName("updated_at") val updatedAt: String? = null,
)

data class ServiceSpecialtyDto(val id: Int = 0, val name: String? = null)

data class ServiceTierDto(
    /** basic · standard · premium */
    val level: String = "basic",
    @SerializedName("level_label") val levelLabel: String? = null,
    val name: String = "",
    val description: String? = null,
    @SerializedName("price_minor") val priceMinor: Long = 0,
    @SerializedName("delivery_days") val deliveryDays: Int = 1,
    val revisions: Int = 0,
    val features: List<String> = emptyList(),
)

data class ServiceAddonDto(
    val id: Int = 0,
    val title: String = "",
    @SerializedName("price_minor") val priceMinor: Long = 0,
    @SerializedName("extra_days") val extraDays: Int = 0,
)

data class ServiceImageDto(val id: Int = 0, val name: String? = null, val path: String? = null)

data class ServiceOrderCountsDto(val awaiting: Int = 0, val active: Int = 0, val completed: Int = 0)

data class ServiceCanDto(
    val edit: Boolean = true,
    val submit: Boolean = false,
    val pause: Boolean = false,
    val resume: Boolean = false,
    val delete: Boolean = true,
)

/** The whole service each time: `tiers` and `addons` replace what was there. */
data class ServiceRequest(
    val title: String,
    val description: String,
    val requirements: String?,
    @SerializedName("specialty_id") val specialtyId: Int?,
    val billing: String,
    val tiers: List<ServiceTierRequest>,
    val addons: List<ServiceAddonRequest>,
    /** FL-L13: sent only when the feature is on. */
    val rights: RightsInputDto? = null,
)

data class ServiceTierRequest(
    val level: String,
    val name: String?,
    val description: String?,
    @SerializedName("price_minor") val priceMinor: Long,
    @SerializedName("delivery_days") val deliveryDays: Int,
    val revisions: Int,
    val features: List<String>,
)

data class ServiceAddonRequest(val title: String, @SerializedName("price_minor") val priceMinor: Long, @SerializedName("extra_days") val extraDays: Int)

data class ServiceOrderDto(
    val uuid: String = "",
    /** awaiting · active · completed · declined · expired · cancelled */
    val status: String = "awaiting",
    val service: ServiceOrderServiceDto = ServiceOrderServiceDto(),
    val level: String = "basic",
    @SerializedName("level_label") val levelLabel: String? = null,
    @SerializedName("tier_name") val tierName: String? = null,
    val features: List<String> = emptyList(),
    val addons: List<ServiceOrderAddonDto> = emptyList(),
    @SerializedName("price_minor") val priceMinor: Long = 0,
    @field:com.google.gson.annotations.JsonAdapter(CurrencyLabelAdapter::class) val currency: String? = null,
    val decimals: Int = 2,
    @SerializedName("delivery_days") val deliveryDays: Int = 0,
    val revisions: Int = 0,
    val note: String? = null,
    /** The project the order runs as — after accepting, its agreement screen. */
    val project: String? = null,
    /** FL-L05: the monthly subscription this order is one month of. */
    val subscription: OrderSubscriptionDto? = null,
    @SerializedName("answer_by") val answerBy: String? = null,
    val money: ServiceOrderMoneyDto = ServiceOrderMoneyDto(),
    val client: ServiceOrderClientDto? = null,
    val can: ServiceOrderCanDto = ServiceOrderCanDto(),
    @SerializedName("created_at") val createdAt: String? = null,
)

data class ServiceOrderServiceDto(val uuid: String? = null, val title: String? = null)

data class ServiceOrderAddonDto(val title: String = "", @SerializedName("price_minor") val priceMinor: Long = 0, @SerializedName("extra_days") val extraDays: Int = 0)

data class ServiceOrderMoneyDto(
    @SerializedName("price_minor") val priceMinor: Long = 0,
    @SerializedName("commission_minor") val commissionMinor: Long = 0,
    @SerializedName("net_minor") val netMinor: Long = 0,
)

data class ServiceOrderClientDto(val name: String? = null)

data class ServiceOrderCanDto(val accept: Boolean = false, val decline: Boolean = false)

data class OrderAnswerRequest(val reason: String? = null)

data class OrderSubscriptionDto(val uuid: String? = null, val cycle: Int = 1)

/** FL-L05: a client's monthly subscription to one of my services, at the terms kept from its start. */
data class ServiceSubscriptionDto(
    val uuid: String = "",
    /** pending · active · ended */
    val status: String = "pending",
    /** first_declined · first_expired · first_cancelled · cancelled_client · cancelled_freelancer · not_renewed */
    @SerializedName("end_reason") val endReason: String? = null,
    val service: ServiceOrderServiceDto = ServiceOrderServiceDto(),
    val level: String = "basic",
    @SerializedName("tier_name") val tierName: String? = null,
    @SerializedName("price_minor") val priceMinor: Long = 0,
    @field:com.google.gson.annotations.JsonAdapter(CurrencyLabelAdapter::class) val currency: String? = null,
    val decimals: Int = 2,
    @SerializedName("delivery_days") val deliveryDays: Int = 0,
    val money: ServiceOrderMoneyDto = ServiceOrderMoneyDto(),
    val cycle: Int = 1,
    @SerializedName("period_ends_at") val periodEndsAt: String? = null,
    val renew: SubscriptionRenewDto? = null,
    val cancel: SubscriptionCancelDto? = null,
    @SerializedName("notice_days") val noticeDays: Int = 7,
    val current: SubscriptionMonthDto? = null,
    val client: ServiceOrderClientDto? = null,
    val can: SubscriptionCanDto = SubscriptionCanDto(),
    @SerializedName("ended_at") val endedAt: String? = null,
)

data class SubscriptionRenewDto(@SerializedName("opens_at") val opensAt: String? = null, @SerializedName("due_by") val dueBy: String? = null, val open: Boolean = false)

/** [by] client · freelancer; it ends on [endsAt]. */
data class SubscriptionCancelDto(val by: String = "", @SerializedName("ends_at") val endsAt: String? = null)

data class SubscriptionMonthDto(val uuid: String = "", val cycle: Int = 1, val status: String = "", val project: String? = null)

data class SubscriptionCanDto(val cancel: Boolean = false, val keep: Boolean = false)
