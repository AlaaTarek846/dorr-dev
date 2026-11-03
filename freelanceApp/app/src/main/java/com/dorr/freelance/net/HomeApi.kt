package com.dorr.freelance.net

import com.dorr.core.network.ApiEnvelope
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.google.gson.annotations.SerializedName
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import retrofit2.Response
import retrofit2.http.GET

/** The home in one answer (`GET freelance/v1/home`): promos, my numbers, what waits for my answer, the next consultation and my running work. */
interface HomeApi {
    @GET("${ApiClient.FREELANCE}home")
    suspend fun show(): Response<ApiEnvelope<HomeDto>>
}

data class HomeDto(
    @SerializedName("server_time") val serverTime: String? = null,
    /** The country's switches: a tab or a card whose feature is off stays hidden. */
    val features: Map<String, Boolean> = emptyMap(),
    val promos: List<PromoDto> = emptyList(),
    val stats: HomeStatsDto = HomeStatsDto(),
    val attention: HomeAttentionDto = HomeAttentionDto(),
    @SerializedName("next_consultation") val nextConsultation: HomeConsultationDto? = null,
    val work: List<HomeWorkDto> = emptyList(),
) {
    fun on(feature: String): Boolean = features[feature] == true
}

/** A banner the staff put on the home: [targetType] `none` / `screen` (one of the app's pages, [target] its key) / `url` (an https page). */
data class PromoDto(
    val uuid: String = "",
    val title: String? = null,
    val body: String? = null,
    val cta: String? = null,
    @SerializedName("image_path") val imagePath: String? = null,
    @SerializedName("target_type") val targetType: String = "none",
    val target: String? = null,
)

data class HomeStatsDto(
    val currency: String? = null,
    val decimals: Int = 2,
    @SerializedName("earned_month_minor") val earnedMonthMinor: Long = 0,
    /** Earned but still held (released after the client's review time). */
    @SerializedName("pending_minor") val pendingMinor: Long = 0,
    @SerializedName("live_offers") val liveOffers: Int = 0,
    @SerializedName("active_projects") val activeProjects: Int = 0,
    /** Null until enough reviews are in. */
    val rating: Double? = null,
    @SerializedName("reviews_count") val reviewsCount: Int = 0,
    @SerializedName("completed_projects") val completedProjects: Int = 0,
)

data class HomeAttentionDto(
    @SerializedName("orders_awaiting") val ordersAwaiting: Int = 0,
    @SerializedName("consultations_pending") val consultationsPending: Int = 0,
    @SerializedName("invitations_pending") val invitationsPending: Int = 0,
    /** Parts the client sent back for changes. */
    val revisions: Int = 0,
) {
    /** What waits on the «My work» tab. */
    val work: Int get() = ordersAwaiting + consultationsPending + revisions
}

data class HomeConsultationDto(
    val uuid: String = "",
    /** `pending` (waiting for my answer) or `booked`. */
    val status: String = "",
    val title: String? = null,
    val minutes: Int = 0,
    @SerializedName("client_name") val clientName: String? = null,
    @SerializedName("starts_at") val startsAt: String? = null,
    @SerializedName("ends_at") val endsAt: String? = null,
)

data class HomeWorkDto(
    @SerializedName("project_uuid") val projectUuid: String? = null,
    val title: String? = null,
    @SerializedName("client_name") val clientName: String? = null,
    val milestone: HomeMilestoneDto? = null,
)

data class HomeMilestoneDto(
    val title: String? = null,
    val status: String? = null,
    @SerializedName("due_at") val dueAt: String? = null,
)

/**
 * The last home answer, shared by the home, the bottom bar's badges and the tabs that hide with a feature. Read on open, when the connection
 * returns and when a notice arrives — never on a timer.
 */
object HomeHub {
    private val _home = MutableStateFlow<HomeDto?>(null)
    val home: StateFlow<HomeDto?> = _home.asStateFlow()

    suspend fun refresh(): ApiResult<HomeDto> {
        val result = apiCall { ApiClient.home.show() }
        if (result is ApiResult.Ok) _home.value = result.data
        return result
    }

    fun clear() {
        _home.value = null
    }
}
