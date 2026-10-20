package com.dorr.driver.net

import com.dorr.core.network.ApiEnvelope
import com.google.gson.JsonObject
import com.google.gson.annotations.SerializedName
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.POST
import retrofit2.http.Path

/** The captain's boat (docs/rides_tasks.md O-25): sea trips and yacht hires, bookings, boarding, more time. */
interface DriverMarineApi {
    @GET("driver/v1/marine/boat")
    suspend fun boat(): Response<ApiEnvelope<MarineBoatInfoDto>>

    @GET("driver/v1/marine/listings")
    suspend fun listings(): Response<ApiEnvelope<List<DriverMarineListingDto>>>

    /** `{kind: trip|rental, title, description?, marina{lat,lng,address}, route_note?, amenities[], capacity?, depart_at?, duration_min?, seat_price_minor?, private_price_minor?, hour_price_minor?, min_hours?}` */
    @POST("driver/v1/marine/listings")
    suspend fun publish(@Body body: JsonObject): Response<ApiEnvelope<DriverMarineListingDto>>

    @GET("driver/v1/marine/listings/{uuid}")
    suspend fun listing(@Path("uuid") uuid: String): Response<ApiEnvelope<DriverMarineListingDto>>

    /** `{to: paused|active|departed|completed|canceled}` */
    @POST("driver/v1/marine/listings/{uuid}/move")
    suspend fun move(@Path("uuid") uuid: String, @Body body: JsonObject): Response<ApiEnvelope<DriverMarineListingDto>>

    /** `{qr}` */
    @POST("driver/v1/marine/board")
    suspend fun board(@Body body: JsonObject): Response<ApiEnvelope<MarineGuestBookingDto>>

    @POST("driver/v1/marine/bookings/{uuid}/finish")
    suspend fun finish(@Path("uuid") uuid: String): Response<ApiEnvelope<MarineGuestBookingDto>>

    /** `{approve: true|false}` */
    @POST("driver/v1/marine/bookings/{uuid}/extension")
    suspend fun extension(@Path("uuid") uuid: String, @Body body: JsonObject): Response<ApiEnvelope<MarineGuestBookingDto>>
}

data class MarineBoatInfoDto(val uuid: String, val type: String? = null, val name: String? = null, val seats: Int = 0, val amenities: List<String> = emptyList())

data class DriverMarinePricesDto(
    @SerializedName("seat_minor") val seatMinor: Long? = null,
    @SerializedName("private_minor") val privateMinor: Long? = null,
    @SerializedName("hour_minor") val hourMinor: Long? = null,
    @SerializedName("min_hours") val minHours: Int? = null,
)

data class MarineGuestDto(val name: String? = null, val phone: String? = null)

data class MarineExtDto(val hours: Int = 0, @SerializedName("amount_minor") val amountMinor: Long = 0, val status: String? = null)

data class MarineGuestBookingDto(
    val uuid: String,
    val mode: String,
    val status: String,
    val guests: Int = 1,
    @SerializedName("starts_at") val startsAt: String? = null,
    @SerializedName("ends_at") val endsAt: String? = null,
    @SerializedName("amount_minor") val amountMinor: Long = 0,
    @SerializedName("extension_minor") val extensionMinor: Long = 0,
    val extension: MarineExtDto? = null,
    val guest: MarineGuestDto? = null,
)

data class DriverMarineListingDto(
    val uuid: String,
    val kind: String,
    val status: String = "active",
    val title: String,
    val description: String? = null,
    val marina: PlaceDto,
    @SerializedName("route_note") val routeNote: String? = null,
    val amenities: List<String> = emptyList(),
    val capacity: Int = 0,
    @SerializedName("seats_left") val seatsLeft: Int? = null,
    @SerializedName("depart_at") val departAt: String? = null,
    @SerializedName("duration_min") val durationMin: Int? = null,
    val prices: DriverMarinePricesDto = DriverMarinePricesDto(),
    val currency: CurrencyDto? = null,
    val bookings: List<MarineGuestBookingDto> = emptyList(),
)
