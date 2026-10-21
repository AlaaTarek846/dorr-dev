package com.dorr.app.network

import com.google.gson.JsonObject
import com.google.gson.annotations.SerializedName
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.POST
import retrofit2.http.Path
import retrofit2.http.Query

/** Find a driver (docs/rides_tasks.md O-26c): a captain to drive my car, by the hour or the day. */
interface HireApi {
    @GET("mobile/v1/rides/hire/quote")
    suspend fun quote(
        @Header("Authorization") auth: String,
        @Query("lat") lat: Double,
        @Query("lng") lng: Double,
        @Query("unit") unit: String,
        @Query("quantity") quantity: Int,
    ): ApiEnvelope<HireQuoteDto>

    /** `{pickup{lat,lng,address}, starts_at?, unit: hour|day, quantity, transmission, car?, note?}` — paid from the wallet. */
    @POST("mobile/v1/rides/hire")
    suspend fun request(@Header("Authorization") auth: String, @Header("Idempotency-Key") key: String, @Body body: JsonObject): ApiEnvelope<HireDto>

    @GET("mobile/v1/rides/hire")
    suspend fun mine(@Header("Authorization") auth: String): ApiEnvelope<List<HireDto>>

    @GET("mobile/v1/rides/hire/{uuid}")
    suspend fun show(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<HireDto>

    @POST("mobile/v1/rides/hire/{uuid}/cancel")
    suspend fun cancel(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<HireDto>
}

data class HireLimitsDto(
    @SerializedName("hour_price_minor") val hourPriceMinor: Long = 0,
    @SerializedName("day_price_minor") val dayPriceMinor: Long = 0,
    @SerializedName("min_hours") val minHours: Int = 1,
    @SerializedName("max_hours") val maxHours: Int = 12,
    @SerializedName("max_days") val maxDays: Int = 7,
    @SerializedName("overtime_per_min_minor") val overtimePerMinMinor: Long = 0,
    @SerializedName("free_cancel_before_s") val freeCancelBeforeS: Int = 0,
)

data class HireQuoteDto(
    @SerializedName("amount_minor") val amountMinor: Long = 0,
    val limits: HireLimitsDto = HireLimitsDto(),
    val currency: RideCurrencyDto? = null,
)

data class HirePersonDto(val name: String? = null, val avatar: String? = null, val phone: String? = null)

data class HireDto(
    val uuid: String,
    /** unpaid / lapsed / open / accepted / arrived / started / completed / canceled / expired */
    val status: String,
    /** O-67: «unpaid» — held for the payment screen until then. */
    @SerializedName("payment_expires_at") val paymentExpiresAt: String? = null,
    @SerializedName("discount_minor") val discountMinor: Long = 0,
    val pickup: BusPlaceDto,
    @SerializedName("starts_at") val startsAt: String? = null,
    @SerializedName("ends_at") val endsAt: String? = null,
    val unit: String = "hour",
    val quantity: Int = 1,
    val transmission: String = "automatic",
    val car: String? = null,
    val note: String? = null,
    @SerializedName("amount_minor") val amountMinor: Long = 0,
    @SerializedName("extra_minor") val extraMinor: Long = 0,
    val currency: RideCurrencyDto? = null,
    @SerializedName("started_at") val startedAt: String? = null,
    val captain: HirePersonDto? = null,
)
