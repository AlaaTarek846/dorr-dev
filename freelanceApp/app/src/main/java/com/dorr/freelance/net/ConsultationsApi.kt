package com.dorr.freelance.net

import com.dorr.core.network.ApiEnvelope
import com.google.gson.annotations.SerializedName
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.DELETE
import retrofit2.http.GET
import retrofit2.http.POST
import retrofit2.http.PUT
import retrofit2.http.Path
import retrofit2.http.Query

/**
 * FL-L07, paid consultations under `/api/freelance/v1/consultations`: my consultation listings (like ready-made services: a title, lengths
 * with a price each and weekly hours in my time zone, reviewed by the staff before clients see them), the bookings clients made, and
 * answering or cancelling one. The session itself is a video call (FL-L16), so there is no room.
 */
interface ConsultationsApi {
    /** What the form needs: lengths, limits, the rules and my numbers. */
    @GET("${ApiClient.FREELANCE}consultations/info")
    suspend fun info(): Response<ApiEnvelope<ConsultationInfoDto>>

    @GET("${ApiClient.FREELANCE}consultations/offers")
    suspend fun offers(): Response<ApiEnvelope<List<ConsultationOfferDto>>>

    @GET("${ApiClient.FREELANCE}consultations/offers/{uuid}")
    suspend fun offer(@Path("uuid") uuid: String): Response<ApiEnvelope<ConsultationOfferDto>>

    @POST("${ApiClient.FREELANCE}consultations/offers")
    suspend fun create(@Body body: ConsultationOfferRequest): Response<ApiEnvelope<ConsultationOfferDto>>

    @PUT("${ApiClient.FREELANCE}consultations/offers/{uuid}")
    suspend fun update(@Path("uuid") uuid: String, @Body body: ConsultationOfferRequest): Response<ApiEnvelope<ConsultationOfferDto>>

    @POST("${ApiClient.FREELANCE}consultations/offers/{uuid}/submit")
    suspend fun submit(@Path("uuid") uuid: String): Response<ApiEnvelope<ConsultationOfferDto>>

    @POST("${ApiClient.FREELANCE}consultations/offers/{uuid}/pause")
    suspend fun pause(@Path("uuid") uuid: String): Response<ApiEnvelope<ConsultationOfferDto>>

    @POST("${ApiClient.FREELANCE}consultations/offers/{uuid}/resume")
    suspend fun resume(@Path("uuid") uuid: String): Response<ApiEnvelope<ConsultationOfferDto>>

    @DELETE("${ApiClient.FREELANCE}consultations/offers/{uuid}")
    suspend fun delete(@Path("uuid") uuid: String): Response<ApiEnvelope<Any>>

    /** `tab` = upcoming · past · cancelled */
    @GET("${ApiClient.FREELANCE}consultations")
    suspend fun list(@Query("tab") tab: String? = null, @Query("page") page: Int = 1, @Query("per_page") perPage: Int = 15): Response<ApiEnvelope<List<ConsultationDto>>>

    @GET("${ApiClient.FREELANCE}consultations/{uuid}")
    suspend fun show(@Path("uuid") uuid: String): Response<ApiEnvelope<ConsultationDto>>

    /** A pending request: take the time (the others for those hours go back). */
    @POST("${ApiClient.FREELANCE}consultations/{uuid}/accept")
    suspend fun accept(@Path("uuid") uuid: String): Response<ApiEnvelope<ConsultationDto>>

    /** A pending request: say no; the client gets everything back. */
    @POST("${ApiClient.FREELANCE}consultations/{uuid}/decline")
    suspend fun decline(@Path("uuid") uuid: String, @Body body: ConsultationCancelRequest): Response<ApiEnvelope<ConsultationDto>>

    @POST("${ApiClient.FREELANCE}consultations/{uuid}/cancel")
    suspend fun cancel(@Path("uuid") uuid: String, @Body body: ConsultationCancelRequest): Response<ApiEnvelope<ConsultationDto>>
}

data class ConsultationInfoDto(
    @SerializedName("feature_on") val featureOn: Boolean = true,
    val lengths: List<Int> = listOf(15, 30, 45, 60, 90),
    @SerializedName("max_options") val maxOptions: Int = 4,
    /** The most consultations listed (published or waiting) at once. */
    @SerializedName("max_active") val maxActive: Int = 5,
    /** The staff look at a new or changed one before clients see it. */
    val review: Boolean = true,
    val currency: String? = null,
    val decimals: Int = 2,
    val price: ConsultationPriceLimitsDto = ConsultationPriceLimitsDto(),
    @SerializedName("suggested_weekly") val suggestedWeekly: List<ConsultationHoursDto> = emptyList(),
    val rules: ConsultationRulesDto = ConsultationRulesDto(),
    val stats: ConsultationStatsDto = ConsultationStatsDto(),
)

/** One consultation I list. `status`: draft · pending_review · published · paused · rejected · suspended. */
data class ConsultationOfferDto(
    val uuid: String,
    val title: String? = null,
    val about: String? = null,
    val status: String = "draft",
    @SerializedName("status_reason") val statusReason: String? = null,
    val options: List<ConsultationOptionDto> = emptyList(),
    val weekly: List<ConsultationHoursDto> = emptyList(),
    val timezone: String? = null,
    val currency: String? = null,
    val decimals: Int = 2,
    /** Open to clients right now. */
    val live: Boolean = false,
    val bookings: ConsultationOfferBookingsDto = ConsultationOfferBookingsDto(),
    val can: ConsultationOfferCanDto = ConsultationOfferCanDto(),
)

data class ConsultationOfferBookingsDto(val pending: Int = 0, val upcoming: Int = 0, val completed: Int = 0)

data class ConsultationOfferCanDto(
    val edit: Boolean = true,
    val submit: Boolean = false,
    val pause: Boolean = false,
    val resume: Boolean = false,
    val delete: Boolean = true,
)

data class ConsultationOptionDto(
    val minutes: Int,
    @SerializedName("price_minor") val priceMinor: Long,
    @SerializedName("commission_minor") val commissionMinor: Long = 0,
    @SerializedName("net_minor") val netMinor: Long = 0,
)

/** `day` 0 = Sunday … 6 = Saturday; `from` / `to` as HH:mm in my time zone. */
data class ConsultationHoursDto(val day: Int, val from: String, val to: String)

data class ConsultationPriceLimitsDto(
    @SerializedName("min_minor") val minMinor: Long? = null,
    @SerializedName("max_minor") val maxMinor: Long? = null,
)

data class ConsultationRulesDto(
    @SerializedName("free_cancel_hours") val freeCancelHours: Int = 24,
    @SerializedName("complaint_hours") val complaintHours: Int = 24,
    @SerializedName("response_hours") val responseHours: Int = 24,
    @SerializedName("min_notice_hours") val minNoticeHours: Int = 2,
    @SerializedName("max_days_ahead") val maxDaysAhead: Int = 30,
)

data class ConsultationStatsDto(
    /** Requests waiting for my answer. */
    val pending: Int = 0,
    val upcoming: Int = 0,
    val completed: Int = 0,
    @SerializedName("earned_minor") val earnedMinor: Long = 0,
)

data class ConsultationOfferRequest(
    val title: String,
    val about: String?,
    val options: List<ConsultationOptionRequest>,
    val weekly: List<ConsultationHoursDto>,
    val timezone: String,
)

data class ConsultationOptionRequest(val minutes: Int, @SerializedName("price_minor") val priceMinor: Long)

data class ConsultationCancelRequest(val reason: String? = null)

data class ConsultationDto(
    val uuid: String,
    /** pending · booked · declined · completed · cancelled · disputed · refunded */
    val status: String,
    /** upcoming · live · ended while booked, else the status */
    val phase: String,
    val title: String? = null,
    val minutes: Int = 30,
    @SerializedName("starts_at") val startsAt: String,
    @SerializedName("ends_at") val endsAt: String,
    val offer: String? = null,
    val topic: String? = null,
    val currency: String? = null,
    val decimals: Int = 2,
    val money: ConsultationMoneyDto = ConsultationMoneyDto(),
    val client: ConsultationPersonDto? = null,
    val rules: ConsultationBookingRulesDto = ConsultationBookingRulesDto(),
    val cancel: ConsultationCancelDto? = null,
    val complaint: ConsultationComplaintDto? = null,
    val resolution: ConsultationResolutionDto? = null,
    @SerializedName("settled_at") val settledAt: String? = null,
    @SerializedName("respond_by") val respondBy: String? = null,
    /** The client's stars once it is over (they show on my profile at once). */
    val review: ConsultationReviewDto = ConsultationReviewDto(),
    val can: ConsultationCanDto = ConsultationCanDto(),
    @SerializedName("created_at") val createdAt: String? = null,
)

data class ConsultationReviewDto(
    @SerializedName("can_rate") val canRate: Boolean = false,
    @SerializedName("rate_until") val rateUntil: String? = null,
    val rating: ConsultationRatingDto? = null,
)

data class ConsultationRatingDto(val stars: Double = 0.0, val comment: String? = null, val removed: Boolean = false, val at: String? = null)

data class ConsultationMoneyDto(
    @SerializedName("price_minor") val priceMinor: Long = 0,
    @SerializedName("commission_minor") val commissionMinor: Long = 0,
    @SerializedName("net_minor") val netMinor: Long = 0,
)

data class ConsultationPersonDto(val name: String? = null)

data class ConsultationBookingRulesDto(
    @SerializedName("free_cancel_until") val freeCancelUntil: String? = null,
    @SerializedName("release_at") val releaseAt: String? = null,
    @SerializedName("complaint_hours") val complaintHours: Int = 24,
    @SerializedName("response_hours") val responseHours: Int = 24,
)

data class ConsultationCancelDto(val by: String? = null, val reason: String? = null, val late: Boolean = false, val at: String? = null)

data class ConsultationComplaintDto(val text: String? = null, val at: String? = null)

data class ConsultationResolutionDto(val decision: String? = null, val note: String? = null, val at: String? = null)

data class ConsultationCanDto(val cancel: Boolean = false, val accept: Boolean = false, val decline: Boolean = false)
