package com.dorr.app.network

import com.google.gson.JsonObject
import com.google.gson.annotations.SerializedName
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.POST
import retrofit2.http.Path
import retrofit2.http.Query

/** Boats & yachts (docs/rides_tasks.md O-25): sea trips and yachts for hire, book, my bookings, more time. */
interface MarineApi {
    /** `kind` = trip | rental (or both); near a place, or everywhere in my country. */
    @GET("mobile/v1/rides/marine/listings")
    suspend fun listings(
        @Header("Authorization") auth: String,
        @Query("kind") kind: String? = null,
        @Query("lat") lat: Double? = null,
        @Query("lng") lng: Double? = null,
    ): ApiEnvelope<List<MarineListingDto>>

    @GET("mobile/v1/rides/marine/listings/{uuid}")
    suspend fun listing(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<MarineListingDto>

    /** `{mode: shared|private|rental, guests, starts_at?, hours?}` — paid from the wallet. */
    @POST("mobile/v1/rides/marine/listings/{uuid}/book")
    suspend fun book(@Header("Authorization") auth: String, @Header("Idempotency-Key") key: String, @Path("uuid") uuid: String, @Body body: JsonObject): ApiEnvelope<MarineBookingDto>

    @GET("mobile/v1/rides/marine/bookings")
    suspend fun bookings(@Header("Authorization") auth: String): ApiEnvelope<List<MarineBookingDto>>

    @GET("mobile/v1/rides/marine/bookings/{uuid}")
    suspend fun booking(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<MarineBookingDto>

    @POST("mobile/v1/rides/marine/bookings/{uuid}/cancel")
    suspend fun cancel(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<MarineBookingDto>

    /** R-42: `{hours}` more on a hire — the captain agrees or not. */
    @POST("mobile/v1/rides/marine/bookings/{uuid}/extend")
    suspend fun extend(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Body body: JsonObject): ApiEnvelope<MarineBookingDto>
}

data class MarinePricesDto(
    @SerializedName("seat_minor") val seatMinor: Long? = null,
    @SerializedName("private_minor") val privateMinor: Long? = null,
    @SerializedName("hour_minor") val hourMinor: Long? = null,
    @SerializedName("min_hours") val minHours: Int? = null,
)

data class MarineBoatDto(val type: String? = null, val make: String? = null, val model: String? = null, val color: String? = null, val seats: Int = 0)

data class MarineSlotDto(val from: String, val to: String)

data class MarineListingDto(
    val uuid: String,
    /** trip (a sea trip at a set time) or rental (a yacht by the hour). */
    val kind: String,
    val status: String = "active",
    val title: String,
    val description: String? = null,
    val marina: BusPlaceDto,
    @SerializedName("route_note") val routeNote: String? = null,
    val amenities: List<String> = emptyList(),
    val capacity: Int = 0,
    @SerializedName("seats_left") val seatsLeft: Int? = null,
    @SerializedName("depart_at") val departAt: String? = null,
    @SerializedName("duration_min") val durationMin: Int? = null,
    val prices: MarinePricesDto = MarinePricesDto(),
    val currency: RideCurrencyDto? = null,
    val busy: List<MarineSlotDto>? = null,
    val boat: MarineBoatDto? = null,
    val captain: BusCaptainDto? = null,
    @SerializedName("captain_phone") val captainPhone: String? = null,
)

data class MarineExtensionDto(val hours: Int = 0, @SerializedName("amount_minor") val amountMinor: Long = 0, val status: String? = null)

data class MarineBookingDto(
    val uuid: String,
    val mode: String,
    val status: String,
    /** O-67: «unpaid» — held for the payment screen until then. */
    @SerializedName("payment_expires_at") val paymentExpiresAt: String? = null,
    @SerializedName("discount_minor") val discountMinor: Long = 0,
    val guests: Int = 1,
    @SerializedName("starts_at") val startsAt: String? = null,
    @SerializedName("ends_at") val endsAt: String? = null,
    @SerializedName("amount_minor") val amountMinor: Long = 0,
    @SerializedName("extension_minor") val extensionMinor: Long = 0,
    val extension: MarineExtensionDto? = null,
    val qr: String? = null,
    @SerializedName("created_at") val createdAt: String? = null,
    val listing: MarineListingDto? = null,
)
