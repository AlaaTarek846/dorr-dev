package com.dorr.app.network

import com.google.gson.annotations.SerializedName
import retrofit2.http.GET
import retrofit2.http.Header

/**
 * The client's freelance hub in one answer (the client redesign, batch B — `GET mobile/v1/freelance/home`): the promo banners for the
 * client, the few numbers that matter, what waits for me, the next consultation, my running projects and a row of ready-made services.
 * Read on open, on a pull, when a freelance notice arrives and when the connection returns — never on a timer.
 */
interface FreelanceHomeApi {
    @GET("mobile/v1/freelance/home")
    suspend fun home(@Header("Authorization") auth: String): ApiEnvelope<FlHomeDto>
}

data class FlHomeDto(
    val service: Boolean = false,
    val features: Map<String, Boolean> = emptyMap(),
    val currency: String? = null,
    val decimals: Int = 2,
    val promos: List<FlPromoDto> = emptyList(),
    val stats: FlHomeStatsDto = FlHomeStatsDto(),
    val attention: FlHomeAttentionDto = FlHomeAttentionDto(),
    @SerializedName("next_consultation") val nextConsultation: FlHomeConsultationDto? = null,
    val projects: List<FreelanceProjectDto> = emptyList(),
    val services: List<MarketServiceCardDto> = emptyList(),
    /** Nothing posted or ordered yet: the hub shows how it works instead of empty numbers. */
    @SerializedName("is_new") val isNew: Boolean = false,
) {
    /** A feature of the country is on (before the first answer, every one counts as on). */
    fun on(feature: String): Boolean = features[feature] ?: false
}

/** A banner the staff wrote: `target_type` none · screen (a page of the hub, see `FreelancePromo::CLIENT_SCREENS`) · url (https). */
data class FlPromoDto(
    val uuid: String = "",
    val title: String? = null,
    val body: String? = null,
    val cta: String? = null,
    @SerializedName("image_path") val imagePath: String? = null,
    @SerializedName("target_type") val targetType: String = "none",
    val target: String? = null,
)

data class FlHomeStatsDto(
    @SerializedName("running_projects") val runningProjects: Int = 0,
    @SerializedName("new_offers") val newOffers: Int = 0,
    @SerializedName("running_orders") val runningOrders: Int = 0,
    @SerializedName("upcoming_consultations") val upcomingConsultations: Int = 0,
    @SerializedName("funded_month_minor") val fundedMonthMinor: Long = 0,
)

/** How many of a kind wait, and the project the newest one is on. */
data class FlWaitingDto(
    val count: Int = 0,
    @SerializedName("project_uuid") val projectUuid: String? = null,
)

data class FlHomeAttentionDto(
    @SerializedName("new_offers") val newOffers: FlWaitingDto = FlWaitingDto(),
    @SerializedName("to_review") val toReview: FlWaitingDto = FlWaitingDto(),
    @SerializedName("to_fund") val toFund: FlWaitingDto = FlWaitingDto(),
    @SerializedName("to_fix") val toFix: FlWaitingDto = FlWaitingDto(),
    @SerializedName("special_offers") val specialOffers: Int = 0,
)

data class FlHomeConsultationDto(
    val uuid: String = "",
    val status: String = "",
    val title: String? = null,
    val minutes: Int? = null,
    @SerializedName("freelancer_name") val freelancerName: String? = null,
    @SerializedName("starts_at") val startsAt: String? = null,
    @SerializedName("ends_at") val endsAt: String? = null,
)
