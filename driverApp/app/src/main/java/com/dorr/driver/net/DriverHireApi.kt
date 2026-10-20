package com.dorr.driver.net

import com.dorr.core.network.ApiEnvelope
import com.google.gson.JsonObject
import com.google.gson.annotations.SerializedName
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.POST
import retrofit2.http.Path

/** Find a driver, the captain's side (docs/rides_tasks.md O-26c). */
interface DriverHireApi {
    @GET("driver/v1/hire/open")
    suspend fun open(): Response<ApiEnvelope<List<HireJobDto>>>

    @GET("driver/v1/hire/mine")
    suspend fun mine(): Response<ApiEnvelope<List<HireJobDto>>>

    @POST("driver/v1/hire/{uuid}/accept")
    suspend fun accept(@Path("uuid") uuid: String): Response<ApiEnvelope<HireJobDto>>

    /** `{to: arrived | started | completed}` */
    @POST("driver/v1/hire/{uuid}/move")
    suspend fun move(@Path("uuid") uuid: String, @Body body: JsonObject): Response<ApiEnvelope<HireJobDto>>

    @POST("driver/v1/hire/{uuid}/release")
    suspend fun release(@Path("uuid") uuid: String): Response<ApiEnvelope<HireJobDto>>
}

data class HireRiderDto(val name: String? = null, val phone: String? = null)

data class HireJobDto(
    val uuid: String,
    val status: String,
    val pickup: PlaceDto,
    @SerializedName("starts_at") val startsAt: String? = null,
    @SerializedName("ends_at") val endsAt: String? = null,
    val unit: String = "hour",
    val quantity: Int = 1,
    val transmission: String = "automatic",
    val car: String? = null,
    val note: String? = null,
    @SerializedName("amount_minor") val amountMinor: Long = 0,
    @SerializedName("extra_minor") val extraMinor: Long = 0,
    val currency: CurrencyDto? = null,
    val rider: HireRiderDto? = null,
)
