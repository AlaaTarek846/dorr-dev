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

/** How the freelancer can be reached (FL-106), under `/api/freelance/v1/profile`: the pause, the guide price, the weekly hours, time off. */
interface AvailabilityApi {
    @GET("${ApiClient.FREELANCE}profile/availability")
    suspend fun show(): Response<ApiEnvelope<AvailabilityDto>>

    @PUT("${ApiClient.FREELANCE}profile/availability")
    suspend fun update(@Body body: AvailabilityRequest): Response<ApiEnvelope<AvailabilityDto>>

    @POST("${ApiClient.FREELANCE}profile/time-off")
    suspend fun addTimeOff(@Body body: TimeOffRequest): Response<ApiEnvelope<TimeOffDto>>

    @DELETE("${ApiClient.FREELANCE}profile/time-off/{id}")
    suspend fun deleteTimeOff(@Path("id") id: Int): Response<ApiEnvelope<Any>>
}

data class AvailabilityDto(
    @SerializedName("accepting_requests") val acceptingRequests: Boolean = true,
    @SerializedName("paused_until") val pausedUntil: String? = null,
    @SerializedName("pause_reason") val pauseReason: String? = null,
    @SerializedName("can_receive_offers") val canReceiveOffers: Boolean = true,
    @SerializedName("on_time_off") val onTimeOff: Boolean = false,
    val rate: RateDto = RateDto(),
    @SerializedName("weekly_hours") val weeklyHours: List<WeeklyHoursDto> = emptyList(),
    @SerializedName("time_off") val timeOff: List<TimeOffDto> = emptyList(),
)

data class RateDto(
    val type: String = "none",
    @SerializedName("min_minor") val minMinor: Long? = null,
    @SerializedName("max_minor") val maxMinor: Long? = null,
)

/** One working day: `day` is 0 = Sunday … 6 = Saturday, `from` / `to` are `HH:MM`. */
data class WeeklyHoursDto(val day: Int, val from: String, val to: String)

data class TimeOffDto(
    val id: Int = 0,
    @SerializedName("starts_on") val startsOn: String = "",
    @SerializedName("ends_on") val endsOn: String = "",
    val reason: String? = null,
)

/** Everything the screen saves in one go; a field left out keeps its value on the server. */
data class AvailabilityRequest(
    @SerializedName("accepting_requests") val acceptingRequests: Boolean,
    @SerializedName("paused_until") val pausedUntil: String?,
    @SerializedName("pause_reason") val pauseReason: String?,
    @SerializedName("rate_type") val rateType: String,
    @SerializedName("rate_min_minor") val rateMinMinor: Long?,
    @SerializedName("rate_max_minor") val rateMaxMinor: Long?,
    @SerializedName("weekly_hours") val weeklyHours: List<WeeklyHoursDto>,
)

data class TimeOffRequest(
    @SerializedName("starts_on") val startsOn: String,
    @SerializedName("ends_on") val endsOn: String,
    val reason: String?,
)
