package com.dorr.freelance.net

import com.dorr.core.network.ApiEnvelope
import com.google.gson.annotations.SerializedName
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.PUT

/**
 * My capacity (FL-L10), under `/api/freelance/v1`: how many projects I take at once — only running work counts — and the clients waiting
 * in line for my services when I am full.
 */
interface CapacityApi {
    @GET("${ApiClient.FREELANCE}capacity")
    suspend fun show(): Response<ApiEnvelope<CapacityDto>>

    @PUT("${ApiClient.FREELANCE}capacity")
    suspend fun update(@Body body: CapacityRequest): Response<ApiEnvelope<CapacityDto>>
}

/** [max] null = no cap. */
data class CapacityRequest(val max: Int?)

data class CapacityDto(
    val max: Int? = null,
    /** The highest cap I may choose. */
    @SerializedName("max_choice") val maxChoice: Int = 20,
    val running: Int = 0,
    /** Places kept for the first in line. */
    val holds: Int = 0,
    val waiting: Int = 0,
    val full: Boolean = false,
    val line: List<WaitlistEntryDto> = emptyList(),
)

data class WaitlistEntryDto(
    val id: Int = 0,
    /** waiting | offered */
    val status: String = "",
    val position: Int? = null,
    @SerializedName("hold_until") val holdUntil: String? = null,
    @SerializedName("joined_at") val joinedAt: String? = null,
    val service: WaitlistServiceDto = WaitlistServiceDto(),
    val client: WaitlistClientDto = WaitlistClientDto(),
)

data class WaitlistServiceDto(val uuid: String? = null, val title: String? = null)

data class WaitlistClientDto(val name: String? = null)
